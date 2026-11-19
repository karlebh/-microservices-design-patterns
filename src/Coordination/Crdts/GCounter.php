<?php

declare(strict_types=1);

namespace Patterns\Coordination\Crdts;

/**
 * Grow-only counter.
 *
 * Each replica counts ONLY its own increments, in its own slot. The total
 * is the sum of all slots. Merging takes the larger value of each slot,
 * which can never lose an increment and never count one twice.
 */
final class GCounter
{
    /** @var array<string, int> replica => how many increments it has made */
    private array $slots = [];

    public function __construct(private readonly string $replica) {}

    public function increment(int $by = 1): void
    {
        $this->slots[$this->replica] = ($this->slots[$this->replica] ?? 0) + $by;
    }

    public function value(): int
    {
        return array_sum($this->slots);
    }

    public function merge(self $other): void
    {
        foreach ($other->slots as $replica => $count) {
            $this->slots[$replica] = max($this->slots[$replica] ?? 0, $count);
        }
    }
}
