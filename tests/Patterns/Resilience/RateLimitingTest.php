<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Patterns\Resilience\RateLimiting\FixedWindowLimiter;
use Patterns\Resilience\RateLimiting\LeakyBucketLimiter;
use Patterns\Resilience\RateLimiting\RateLimiter;
use Patterns\Resilience\RateLimiting\SlidingWindowCounterLimiter;
use Patterns\Resilience\RateLimiting\SlidingWindowLogLimiter;
use Patterns\Resilience\RateLimiting\TokenBucketLimiter;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitingTest extends TestCase
{
    /** Send $count requests right now and report how many were accepted. */
    private function accepted(RateLimiter $limiter, int $count, string $key = 'client-a'): int
    {
        $accepted = 0;

        for ($i = 0; $i < $count; $i++) {
            $accepted += (int) $limiter->allow($key);
        }

        return $accepted;
    }

    // ---- Things every algorithm must do -------------------------------------------------

    /** @return array<string, array{callable(FakeClock): RateLimiter}> */
    public static function limitersAllowingFiveAtOnce(): array
    {
        return [
            'fixed window' => [fn (FakeClock $clock) => new FixedWindowLimiter(5, 1_000, $clock)],
            'sliding window log' => [fn (FakeClock $clock) => new SlidingWindowLogLimiter(5, 1_000, $clock)],
            'sliding window counter' => [fn (FakeClock $clock) => new SlidingWindowCounterLimiter(5, 1_000, $clock)],
            'token bucket' => [fn (FakeClock $clock) => new TokenBucketLimiter(5, 5.0, $clock)],
            'leaky bucket' => [fn (FakeClock $clock) => new LeakyBucketLimiter(5, 5.0, $clock)],
        ];
    }

    #[DataProvider('limitersAllowingFiveAtOnce')]
    public function test_requests_over_the_limit_are_rejected(callable $make): void
    {
        $limiter = $make(new FakeClock(10_000));

        $this->assertSame(5, $this->accepted($limiter, 8), 'Five get through, three are told 429.');
    }

    #[DataProvider('limitersAllowingFiveAtOnce')]
    public function test_each_client_has_its_own_allowance(callable $make): void
    {
        $limiter = $make(new FakeClock(10_000));

        $this->accepted($limiter, 8, 'noisy-client');

        $this->assertTrue($limiter->allow('quiet-client'), 'One client exhausting its limit does not affect another.');
    }

    #[DataProvider('limitersAllowingFiveAtOnce')]
    public function test_the_allowance_comes_back_with_time(callable $make): void
    {
        $clock = new FakeClock(10_000);
        $limiter = $make($clock);

        $this->accepted($limiter, 8);
        $clock->advance(2_000);

        $this->assertSame(5, $this->accepted($limiter, 8));
    }

    // ---- What makes each algorithm different --------------------------------------------

    public function test_fixed_window_allows_a_double_burst_at_the_boundary(): void
    {
        $clock = new FakeClock(10_900); // 100 ms before the window [10s, 11s) ends
        $limiter = new FixedWindowLimiter(5, 1_000, $clock);

        $beforeBoundary = $this->accepted($limiter, 5);
        $clock->advance(200);         // now 100 ms into the next window
        $afterBoundary = $this->accepted($limiter, 5);

        $this->assertSame(10, $beforeBoundary + $afterBoundary, 'Ten requests in 200 ms against a limit of five per second.');
    }

    public function test_sliding_window_log_has_no_boundary_to_exploit(): void
    {
        $clock = new FakeClock(10_900);
        $limiter = new SlidingWindowLogLimiter(5, 1_000, $clock);

        $before = $this->accepted($limiter, 5);
        $clock->advance(200);
        $after = $this->accepted($limiter, 5);

        $this->assertSame(5, $before + $after, 'The earlier five are still inside the last second.');

        $clock->advance(801); // the first five have now slid out of the window
        $this->assertSame(5, $this->accepted($limiter, 5));
    }

    public function test_sliding_window_counter_approximates_the_log_with_two_counters(): void
    {
        $clock = new FakeClock(10_900);
        $limiter = new SlidingWindowCounterLimiter(5, 1_000, $clock);

        $before = $this->accepted($limiter, 5);
        $clock->advance(200); // 10% into the next window: 90% of the previous five still count (4.5)
        $after = $this->accepted($limiter, 5);

        $this->assertSame(5, $before);
        $this->assertSame(1, $after, 'Room for one more (4.5 + 1 > 5 after that), not for five like the fixed window.');
    }

    public function test_token_bucket_saves_up_for_a_burst_but_only_to_its_capacity(): void
    {
        $clock = new FakeClock;
        $limiter = new TokenBucketLimiter(capacity: 10, refillPerSecond: 1.0, clock: $clock);

        $this->assertSame(10, $this->accepted($limiter, 15), 'A full bucket serves a burst of ten.');

        $clock->advanceSeconds(3);
        $this->assertSame(3, $this->accepted($limiter, 15), 'Three seconds at one token per second.');

        $clock->advanceSeconds(3_600);
        $this->assertSame(10, $this->accepted($limiter, 15), 'An hour of silence does not bank 3,600 tokens.');
    }

    public function test_token_bucket_sustains_exactly_the_refill_rate(): void
    {
        $clock = new FakeClock;
        $limiter = new TokenBucketLimiter(capacity: 1, refillPerSecond: 2.0, clock: $clock);
        $accepted = 0;

        // A client hammering every 100 ms for ten seconds.
        for ($tick = 0; $tick < 100; $tick++) {
            $accepted += (int) $limiter->allow('client-a');
            $clock->advance(100);
        }

        $this->assertSame(20, $accepted, 'Two per second, however often it asks.');
    }

    public function test_leaky_bucket_smooths_traffic_to_a_constant_rate(): void
    {
        $clock = new FakeClock;
        $limiter = new LeakyBucketLimiter(capacity: 3, leakPerSecond: 1.0, clock: $clock);

        $this->assertSame(3, $this->accepted($limiter, 10), 'The bucket fills and the rest overflows.');

        // From now on only one request per second fits, no matter how many arrive.
        $perSecond = [];

        for ($second = 0; $second < 5; $second++) {
            $clock->advanceSeconds(1);
            $perSecond[] = $this->accepted($limiter, 10);
        }

        $this->assertSame([1, 1, 1, 1, 1], $perSecond);
    }

    public function test_an_idle_period_earns_a_burst_from_a_token_bucket_but_not_more_than_capacity_from_a_leaky_one(): void
    {
        $clock = new FakeClock;
        $token = new TokenBucketLimiter(capacity: 10, refillPerSecond: 1.0, clock: $clock);
        $leaky = new LeakyBucketLimiter(capacity: 2, leakPerSecond: 1.0, clock: $clock);

        // Both are configured for a sustained ONE request per second.
        $this->accepted($token, 20);
        $this->accepted($leaky, 20);
        $clock->advanceSeconds(60);

        $this->assertSame(10, $this->accepted($token, 20), 'Token bucket: generous to a client that has been quiet.');
        $this->assertSame(2, $this->accepted($leaky, 20), 'Leaky bucket: the downstream still sees a trickle.');
    }
}
