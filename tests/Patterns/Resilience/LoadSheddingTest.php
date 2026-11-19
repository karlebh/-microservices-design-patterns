<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Patterns\Resilience\LoadShedding\AdaptiveConcurrencyLimit;
use Patterns\Resilience\LoadShedding\LoadShedder;
use Patterns\Resilience\LoadShedding\Priority;
use Patterns\Resilience\LoadShedding\Throttler;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class LoadSheddingTest extends TestCase
{
    /** Put the server at a given number of requests in progress. */
    private function busy(LoadShedder $shedder, int $inFlight): void
    {
        for ($i = 0; $i < $inFlight; $i++) {
            $shedder->tryAdmit(Priority::Critical);
        }
    }

    // ---- Load shedding ------------------------------------------------------------------

    public function test_a_server_with_headroom_admits_everything(): void
    {
        $shedder = new LoadShedder(capacity: 100);
        $this->busy($shedder, 50);

        foreach (Priority::cases() as $priority) {
            $this->assertTrue($shedder->tryAdmit($priority));
        }
    }

    public function test_the_least_important_work_is_shed_first(): void
    {
        $shedder = new LoadShedder(capacity: 100);
        $this->busy($shedder, 75);

        $this->assertFalse($shedder->tryAdmit(Priority::Sheddable), 'Analytics can wait.');
        $this->assertTrue($shedder->tryAdmit(Priority::Normal));
        $this->assertTrue($shedder->tryAdmit(Priority::Critical));
    }

    public function test_close_to_the_limit_only_critical_work_is_admitted(): void
    {
        $shedder = new LoadShedder(capacity: 100);
        $this->busy($shedder, 95);

        $this->assertFalse($shedder->tryAdmit(Priority::Sheddable));
        $this->assertFalse($shedder->tryAdmit(Priority::Normal), 'Browsing is refused so that...');
        $this->assertTrue($shedder->tryAdmit(Priority::Critical), '...checkout still gets through.');
    }

    public function test_a_full_server_refuses_everything_rather_than_queue_it(): void
    {
        $shedder = new LoadShedder(capacity: 100);
        $this->busy($shedder, 100);

        $this->assertFalse($shedder->tryAdmit(Priority::Critical));
        $this->assertSame(1.0, $shedder->utilization(), 'It never takes on more than it can handle.');
    }

    public function test_capacity_returns_as_requests_finish(): void
    {
        $shedder = new LoadShedder(capacity: 10);
        $this->busy($shedder, 10);
        $this->assertFalse($shedder->tryAdmit(Priority::Normal));

        foreach (range(1, 5) as $_) {
            $shedder->finish();
        }

        $this->assertTrue($shedder->tryAdmit(Priority::Normal));
        $this->assertSame(['Critical' => 0, 'Normal' => 1, 'Sheddable' => 0], $shedder->shed, 'What was shed is counted: it is the signal to add capacity.');
    }

    public function test_under_a_traffic_spike_critical_requests_still_succeed(): void
    {
        $shedder = new LoadShedder(capacity: 100);
        $admitted = ['Critical' => 0, 'Normal' => 0, 'Sheddable' => 0];

        // 300 requests arrive at once: a third of each priority, interleaved.
        for ($i = 0; $i < 100; $i++) {
            foreach ([Priority::Sheddable, Priority::Normal, Priority::Critical] as $priority) {
                if ($shedder->tryAdmit($priority)) {
                    $admitted[$priority->name]++;
                }
            }
        }

        $this->assertSame(100, array_sum($admitted), 'Exactly the capacity was admitted, never more.');
        $this->assertGreaterThan($admitted['Normal'], $admitted['Critical']);
        $this->assertGreaterThan($admitted['Sheddable'], $admitted['Normal']);
    }

    // ---- Adaptive concurrency limit -----------------------------------------------------

    public function test_the_limit_creeps_up_while_responses_are_fast(): void
    {
        $limit = new AdaptiveConcurrencyLimit(initialLimit: 10, targetLatencyMs: 100);

        foreach (range(1, 200) as $_) {
            $limit->onSample(latencyMs: 20);
        }

        $this->assertGreaterThan(20, $limit->limit());
        $this->assertLessThan(40, $limit->limit(), 'Additive increase is deliberately slow.');
    }

    public function test_the_limit_drops_sharply_when_responses_get_slow(): void
    {
        $limit = new AdaptiveConcurrencyLimit(initialLimit: 100, targetLatencyMs: 100);

        foreach (range(1, 10) as $_) {
            $limit->onSample(latencyMs: 500);
        }

        $this->assertSame(34, $limit->limit(), 'Ten slow responses cut the limit by two thirds (0.9^10).');
    }

    public function test_the_limit_settles_just_below_the_point_where_the_system_degrades(): void
    {
        // A system that is fast up to 30 concurrent requests and slow beyond that.
        // Nobody tells the limiter this number.
        $latencyAt = fn (int $concurrency) => $concurrency <= 30 ? 40 : 400;

        $limit = new AdaptiveConcurrencyLimit(initialLimit: 5, targetLatencyMs: 100);
        $observed = [];

        for ($round = 0; $round < 3_000; $round++) {
            $limit->onSample($latencyAt($limit->limit()));

            if ($round >= 2_000) {
                $observed[] = $limit->limit();
            }
        }

        $this->assertGreaterThanOrEqual(26, min($observed));
        $this->assertLessThanOrEqual(31, max($observed), 'It found the knee by probing, and oscillates right under it.');
    }

    // ---- Request throttling -------------------------------------------------------------

    public function test_a_client_over_its_rate_is_told_when_to_come_back(): void
    {
        $clock = new FakeClock;
        $throttler = new Throttler(requestsPerSecond: 2.0, burst: 3, clock: $clock);

        $this->assertSame([true, true, true], array_column(array_map(fn () => $throttler->attempt('client-a'), range(1, 3)), 'allowed'));

        $denied = $throttler->attempt('client-a');

        $this->assertFalse($denied['allowed']);
        $this->assertSame(500, $denied['retryAfterMs'], 'At 2 requests/second the next token arrives in half a second.');

        $clock->advance($denied['retryAfterMs']);
        $this->assertTrue($throttler->attempt('client-a')['allowed'], 'A client that honours Retry-After succeeds on its next try.');
    }

    public function test_throttling_is_per_client_and_independent_of_server_load(): void
    {
        $throttler = new Throttler(requestsPerSecond: 1.0, burst: 2, clock: new FakeClock);

        foreach (range(1, 10) as $_) {
            $throttler->attempt('greedy-client');
        }

        $response = $throttler->attempt('polite-client');

        $this->assertTrue($response['allowed'], 'One client cannot use up another client\'s share.');
        $this->assertSame(1, $response['remaining']);
    }
}
