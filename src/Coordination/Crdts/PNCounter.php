<?php

declare(strict_types=1);

namespace Patterns\Coordination\Crdts;

/**
 * Positive-negative counter: a counter that can go down as well as up.
 *
 * A G-counter cannot decrement (taking the max would undo it). So keep
 * two G-counters — one for increments, one for decrements — and report
 * their difference. Each half only ever grows, so each half merges safely.
 */
final class PNCounter
{
    private GCounter $increments;

    private GCounter $decrements;

    public function __construct(string $replica)
    {
        $this->increments = new GCounter($replica);
        $this->decrements = new GCounter($replica);
    }

    public function increment(int $by = 1): void
    {
        $this->increments->increment($by);
    }

    public function decrement(int $by = 1): void
    {
        $this->decrements->increment($by);
    }

    public function value(): int
    {
        return $this->increments->value() - $this->decrements->value();
    }

    public function merge(self $other): void
    {
        $this->increments->merge($other->increments);
        $this->decrements->merge($other->decrements);
    }
}
