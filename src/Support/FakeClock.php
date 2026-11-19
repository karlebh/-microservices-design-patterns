<?php

declare(strict_types=1);

namespace Patterns\Support;

use InvalidArgumentException;

/**
 * A clock that only moves when you tell it to.
 *
 * Lets a test say "30 seconds later..." without sleeping, which keeps every
 * time-based pattern in this repo fast and fully deterministic.
 */
final class FakeClock implements Clock
{
    public function __construct(private int $nowMs = 0) {}

    public function nowMs(): int
    {
        return $this->nowMs;
    }

    public function advance(int $ms): void
    {
        if ($ms < 0) {
            throw new InvalidArgumentException('Time cannot move backwards.');
        }

        $this->nowMs += $ms;
    }

    public function advanceSeconds(float $seconds): void
    {
        $this->advance((int) round($seconds * 1000));
    }
}
