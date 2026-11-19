<?php

declare(strict_types=1);

namespace Patterns\Resilience\RetryWithBackoff;

use Random\Randomizer;

/**
 * Answers one question: "this was failure number N — how long should I
 * wait before trying again?"
 */
final class BackoffPolicy
{
    private readonly Randomizer $random;

    public function __construct(
        private readonly int $baseMs = 100,
        private readonly int $capMs = 10_000,
        private readonly JitterStrategy $jitter = JitterStrategy::Full,
        ?Randomizer $random = null,
    ) {
        $this->random = $random ?? new Randomizer;
    }

    /**
     * @param  int  $attempt  the attempt that just failed, starting at 1
     * @param  int|null  $previousDelayMs  the delay used before that attempt (only Decorrelated needs it)
     */
    public function delayMs(int $attempt, ?int $previousDelayMs = null): int
    {
        // base * 2^(attempt-1), capped. The shift is bounded so it cannot overflow.
        $exponential = min($this->capMs, $this->baseMs * (1 << min($attempt - 1, 30)));

        return match ($this->jitter) {
            JitterStrategy::None => $exponential,

            JitterStrategy::Full => $this->random->getInt(0, $exponential),

            JitterStrategy::Equal => intdiv($exponential, 2) + $this->random->getInt(0, intdiv($exponential, 2)),

            JitterStrategy::Decorrelated => min(
                $this->capMs,
                $this->random->getInt($this->baseMs, max($this->baseMs, ($previousDelayMs ?? $this->baseMs) * 3)),
            ),
        };
    }
}
