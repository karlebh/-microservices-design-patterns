<?php

declare(strict_types=1);

namespace Patterns\Coordination\LogicalClocks;

/**
 * A vector clock: one counter per process, saying how many of that
 * process's events are "known" at this point.
 *
 * Immutable: every operation returns a new clock, so a clock attached to
 * an event or a message can never change afterwards.
 */
final readonly class VectorClock
{
    /** @param array<string, int> $counters process => number of its events seen */
    public function __construct(private array $counters = []) {}

    /** A local event on $process. */
    public function increment(string $process): self
    {
        return new self([...$this->counters, $process => ($this->counters[$process] ?? 0) + 1]);
    }

    /** Combine knowledge: for each process, the higher of the two counts. */
    public function merge(self $other): self
    {
        $merged = $this->counters;

        foreach ($other->counters as $process => $count) {
            $merged[$process] = max($merged[$process] ?? 0, $count);
        }

        return new self($merged);
    }

    public function compare(self $other): Ordering
    {
        $someSmaller = $someLarger = false;

        foreach (array_keys($this->counters + $other->counters) as $process) {
            $mine = $this->counters[$process] ?? 0;
            $theirs = $other->counters[$process] ?? 0;

            $someSmaller = $someSmaller || $mine < $theirs;
            $someLarger = $someLarger || $mine > $theirs;
        }

        return match (true) {
            $someSmaller && $someLarger => Ordering::Concurrent, // each knows something the other doesn't
            $someSmaller => Ordering::Before,
            $someLarger => Ordering::After,
            default => Ordering::Equal,
        };
    }

    public function happenedBefore(self $other): bool
    {
        return $this->compare($other) === Ordering::Before;
    }

    public function isConcurrentWith(self $other): bool
    {
        return $this->compare($other) === Ordering::Concurrent;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        $counters = $this->counters;
        ksort($counters);

        return $counters;
    }
}
