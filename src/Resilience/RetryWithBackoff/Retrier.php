<?php

declare(strict_types=1);

namespace Patterns\Resilience\RetryWithBackoff;

use Closure;
use Throwable;

/**
 * Runs an operation, retrying failures with the delays a BackoffPolicy
 * prescribes.
 *
 * Sleeping is injected, so tests run instantly and can inspect the exact
 * delays; in production pass `fn (int $ms) => usleep($ms * 1000)`.
 */
final class Retrier
{
    /** @var list<int> every delay that was slept, in milliseconds */
    public array $delays = [];

    private readonly Closure $sleep;

    private readonly Closure $isRetryable;

    /**
     * @param  (Closure(int): void)|null  $sleep  receives the delay in milliseconds
     * @param  (Closure(Throwable): bool)|null  $isRetryable  return false for errors that can never succeed on retry
     */
    public function __construct(
        private readonly BackoffPolicy $policy,
        private readonly int $maxAttempts = 5,
        ?Closure $sleep = null,
        ?Closure $isRetryable = null,
        private readonly ?RetryBudget $budget = null,
    ) {
        $this->sleep = $sleep ?? static fn (int $ms) => usleep($ms * 1000);
        $this->isRetryable = $isRetryable ?? static fn (Throwable $e) => true;
    }

    /**
     * @param  Closure(int $attempt): mixed  $operation
     *
     * @throws Throwable the last failure, once attempts (or the retry budget) are used up
     */
    public function run(Closure $operation): mixed
    {
        $this->budget?->recordRequest();
        $previousDelay = null;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation($attempt);
            } catch (Throwable $e) {
                $outOfAttempts = $attempt >= $this->maxAttempts;
                $pointless = ! ($this->isRetryable)($e);

                // tryConsume() is evaluated last so a retry we won't make doesn't spend budget.
                if ($outOfAttempts || $pointless || $this->budget?->tryConsume() === false) {
                    throw $e;
                }

                $previousDelay = $this->policy->delayMs($attempt, $previousDelay);
                $this->delays[] = $previousDelay;

                ($this->sleep)($previousDelay);
            }
        }
    }
}
