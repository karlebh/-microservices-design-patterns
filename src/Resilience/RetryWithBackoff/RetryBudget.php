<?php

declare(strict_types=1);

namespace Patterns\Resilience\RetryWithBackoff;

/**
 * Limits retries to a fraction of normal traffic.
 *
 * Per-request retry limits don't protect a dependency that is failing for
 * EVERYONE: if each of 1,000 requests retries 3 times, load quadruples at
 * the worst possible moment (a retry storm). A budget is shared by all
 * requests of a client:
 *
 *   every request deposits `ratio` tokens     (0.1 = "10% of traffic may be retries")
 *   every retry withdraws one token
 *
 * While failures are rare the budget is never noticed. During an outage it
 * runs dry and retries stop, so load stays close to 1x instead of multiplying.
 */
final class RetryBudget
{
    private float $tokens;

    public int $retriesDenied = 0;

    /**
     * @param  float  $ratio  tokens earned per request
     * @param  float  $maxTokens  ceiling, so a long quiet period can't bank an unlimited burst
     * @param  float  $initialTokens  lets the very first requests retry
     */
    public function __construct(
        private readonly float $ratio = 0.1,
        private readonly float $maxTokens = 10.0,
        float $initialTokens = 1.0,
    ) {
        $this->tokens = $initialTokens;
    }

    public function recordRequest(): void
    {
        $this->tokens = min($this->maxTokens, $this->tokens + $this->ratio);
    }

    public function tryConsume(): bool
    {
        if ($this->tokens < 1.0) {
            $this->retriesDenied++;

            return false;
        }

        $this->tokens -= 1.0;

        return true;
    }
}
