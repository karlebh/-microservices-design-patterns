<?php

declare(strict_types=1);

namespace Patterns\Coordination\TombstonesAndVersionVectors;

/**
 * For one stored value: how many updates from each replica it incorporates.
 *
 * {A: 2, B: 1} reads "this value includes A's first two updates and B's
 * first one". Comparing two vectors tells whether one value was derived
 * from the other or whether they were written independently.
 */
final readonly class VersionVector
{
    /** @param array<string, int> $counters */
    public function __construct(private array $counters = []) {}

    public function increment(string $replica): self
    {
        return new self([...$this->counters, $replica => ($this->counters[$replica] ?? 0) + 1]);
    }

    public function merge(self $other): self
    {
        $merged = $this->counters;

        foreach ($other->counters as $replica => $count) {
            $merged[$replica] = max($merged[$replica] ?? 0, $count);
        }

        return new self($merged);
    }

    /** True if this version has seen everything the other has (it is the same or a descendant). */
    public function dominates(self $other): bool
    {
        foreach ($other->counters as $replica => $count) {
            if (($this->counters[$replica] ?? 0) < $count) {
                return false;
            }
        }

        return true;
    }

    public function equals(self $other): bool
    {
        return $this->dominates($other) && $other->dominates($this);
    }

    /** Neither descends from the other: they are conflicting, independent writes. */
    public function isConcurrentWith(self $other): bool
    {
        return ! $this->dominates($other) && ! $other->dominates($this);
    }
}
