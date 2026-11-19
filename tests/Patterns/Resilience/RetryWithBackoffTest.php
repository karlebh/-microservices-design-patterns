<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use InvalidArgumentException;
use Patterns\Resilience\RetryWithBackoff\BackoffPolicy;
use Patterns\Resilience\RetryWithBackoff\JitterStrategy;
use Patterns\Resilience\RetryWithBackoff\Retrier;
use Patterns\Resilience\RetryWithBackoff\RetryBudget;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

final class RetryWithBackoffTest extends TestCase
{
    private function seeded(int $seed = 42): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }

    /** A retrier that records delays instead of really sleeping. */
    private function retrier(BackoffPolicy $policy, int $maxAttempts = 5, ?\Closure $isRetryable = null, ?RetryBudget $budget = null): Retrier
    {
        return new Retrier($policy, $maxAttempts, sleep: fn (int $ms) => null, isRetryable: $isRetryable, budget: $budget);
    }

    // ---- The delay calculation ----------------------------------------------------------

    public function test_exponential_backoff_doubles_the_delay_up_to_a_cap(): void
    {
        $policy = new BackoffPolicy(baseMs: 100, capMs: 1_000, jitter: JitterStrategy::None);

        $this->assertSame(
            [100, 200, 400, 800, 1_000, 1_000],
            array_map(fn (int $attempt) => $policy->delayMs($attempt), range(1, 6)),
        );
    }

    public function test_full_jitter_picks_a_random_delay_below_the_exponential_value(): void
    {
        $policy = new BackoffPolicy(baseMs: 100, capMs: 10_000, jitter: JitterStrategy::Full, random: $this->seeded());

        foreach (range(1, 6) as $attempt) {
            $ceiling = 100 * 2 ** ($attempt - 1);

            foreach (range(1, 50) as $_) {
                $delay = $policy->delayMs($attempt);
                $this->assertGreaterThanOrEqual(0, $delay);
                $this->assertLessThanOrEqual($ceiling, $delay);
            }
        }
    }

    public function test_equal_jitter_never_waits_less_than_half_the_exponential_value(): void
    {
        $policy = new BackoffPolicy(baseMs: 100, capMs: 10_000, jitter: JitterStrategy::Equal, random: $this->seeded());

        foreach (range(1, 50) as $_) {
            $delay = $policy->delayMs(4); // exponential value: 800
            $this->assertGreaterThanOrEqual(400, $delay);
            $this->assertLessThanOrEqual(800, $delay);
        }
    }

    public function test_decorrelated_jitter_grows_from_the_previous_delay(): void
    {
        $policy = new BackoffPolicy(baseMs: 100, capMs: 2_000, jitter: JitterStrategy::Decorrelated, random: $this->seeded());
        $previous = null;

        foreach (range(1, 30) as $attempt) {
            $delay = $policy->delayMs($attempt, $previous);

            $this->assertGreaterThanOrEqual(100, $delay);
            $this->assertLessThanOrEqual(min(2_000, ($previous ?? 100) * 3), $delay);

            $previous = $delay;
        }
    }

    public function test_jitter_breaks_up_the_thundering_herd(): void
    {
        // 1,000 clients all failed at the same instant. When does each make its second retry?
        $retryTimes = function (JitterStrategy $jitter): array {
            $policy = new BackoffPolicy(baseMs: 100, capMs: 10_000, jitter: $jitter, random: $this->seeded());

            return array_map(fn () => $policy->delayMs(1) + $policy->delayMs(2), range(1, 1_000));
        };

        $busiestMillisecond = fn (array $times) => max(array_count_values($times));

        $this->assertSame(1_000, $busiestMillisecond($retryTimes(JitterStrategy::None)), 'Without jitter all 1,000 retries land in the same millisecond.');
        $this->assertLessThan(30, $busiestMillisecond($retryTimes(JitterStrategy::Full)), 'With full jitter they are spread over 300 ms.');
    }

    // ---- Retrying an operation ----------------------------------------------------------

    public function test_a_transient_failure_is_retried_until_it_succeeds(): void
    {
        $retrier = $this->retrier(new BackoffPolicy(100, 10_000, JitterStrategy::None));

        $result = $retrier->run(function (int $attempt) {
            if ($attempt < 3) {
                throw new RuntimeException('connection reset');
            }

            return "succeeded on attempt {$attempt}";
        });

        $this->assertSame('succeeded on attempt 3', $result);
        $this->assertSame([100, 200], $retrier->delays, 'Two failures, two waits, each longer than the last.');
    }

    public function test_it_gives_up_after_the_maximum_number_of_attempts(): void
    {
        $retrier = $this->retrier(new BackoffPolicy(100, 10_000, JitterStrategy::None), maxAttempts: 4);
        $calls = 0;

        try {
            $retrier->run(function () use (&$calls) {
                $calls++;

                throw new RuntimeException('still down');
            });
            $this->fail('The last failure should be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('still down', $e->getMessage());
        }

        $this->assertSame(4, $calls);
        $this->assertSame([100, 200, 400], $retrier->delays, 'No sleep after the final attempt.');
    }

    public function test_errors_that_cannot_succeed_are_not_retried(): void
    {
        $retrier = $this->retrier(
            new BackoffPolicy,
            isRetryable: fn (\Throwable $e) => ! $e instanceof InvalidArgumentException,
        );
        $calls = 0;

        try {
            $retrier->run(function () use (&$calls) {
                $calls++;

                throw new InvalidArgumentException('amount must be positive');
            });
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(1, $calls, 'Sending the same invalid request again would fail the same way.');
        $this->assertSame([], $retrier->delays);
    }

    // ---- Retry budget -------------------------------------------------------------------

    public function test_a_retry_budget_stops_a_retry_storm_during_an_outage(): void
    {
        $outage = fn () => throw new RuntimeException('service unavailable');

        $callsDuringOutage = function (?RetryBudget $budget) use ($outage): int {
            $calls = 0;

            // 100 requests arrive while the dependency is completely down.
            for ($request = 0; $request < 100; $request++) {
                try {
                    $this->retrier(new BackoffPolicy, maxAttempts: 4, budget: $budget)->run(function () use (&$calls, $outage) {
                        $calls++;
                        $outage();
                    });
                } catch (RuntimeException) {
                    // the request fails either way
                }
            }

            return $calls;
        };

        $withoutBudget = $callsDuringOutage(null);
        $withBudget = $callsDuringOutage(new RetryBudget(ratio: 0.1));

        $this->assertSame(400, $withoutBudget, 'Every request tries 4 times: the struggling service gets 4x the load.');
        $this->assertLessThanOrEqual(112, $withBudget, 'With a 10% budget it gets about 1.1x.');
        $this->assertGreaterThan(100, $withBudget, 'A few retries are still allowed.');
    }

    public function test_a_retry_budget_goes_unnoticed_when_failures_are_rare(): void
    {
        $budget = new RetryBudget(ratio: 0.1);
        $succeeded = 0;

        for ($request = 1; $request <= 200; $request++) {
            $flaky = $request % 20 === 0; // 5% of requests fail once

            $this->retrier(new BackoffPolicy, budget: $budget)->run(function (int $attempt) use ($flaky, &$succeeded) {
                if ($flaky && $attempt === 1) {
                    throw new RuntimeException('blip');
                }

                $succeeded++;
            });
        }

        $this->assertSame(200, $succeeded);
        $this->assertSame(0, $budget->retriesDenied);
    }
}
