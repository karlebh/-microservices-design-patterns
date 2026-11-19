<?php

declare(strict_types=1);

namespace Patterns\Resilience\LoadShedding;

/**
 * A concurrency limit that tunes itself (AIMD: additive increase,
 * multiplicative decrease — the rule TCP uses for congestion control).
 *
 * The right limit isn't a constant: it depends on hardware, on the
 * workload mix and on how the dependencies are doing right now. So instead
 * of configuring it, measure:
 *
 *   response was fast          -> the system has headroom: raise the limit a little
 *   response was slow / failed -> we are past the knee: cut the limit sharply
 *
 * Probing gently and backing off hard keeps the limit hovering just below
 * the point where latency starts to climb.
 */
final class AdaptiveConcurrencyLimit
{
    private float $limit;

    public function __construct(
        int $initialLimit = 20,
        private readonly int $minLimit = 1,
        private readonly int $maxLimit = 1_000,
        private readonly int $targetLatencyMs = 100,
        private readonly float $decreaseFactor = 0.9,
    ) {
        $this->limit = (float) $initialLimit;
    }

    /** Feed in the outcome of one completed request. */
    public function onSample(int $latencyMs, bool $failed = false): void
    {
        if ($failed || $latencyMs > $this->targetLatencyMs) {
            // Multiplicative decrease: get out of trouble quickly.
            $this->limit = max($this->minLimit, $this->limit * $this->decreaseFactor);

            return;
        }

        // Additive increase: +1 after roughly `limit` good responses.
        $this->limit = min($this->maxLimit, $this->limit + 1 / $this->limit);
    }

    /** How many requests may currently be in progress. */
    public function limit(): int
    {
        return (int) floor($this->limit);
    }
}
