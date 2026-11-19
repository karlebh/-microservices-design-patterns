<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\StreamingVsQueuing;

use OutOfRangeException;

/**
 * An event stream: an append-only log. Reading never removes anything; each
 * consumer group only keeps a cursor (its offset) into the shared log.
 */
final class EventStream
{
    /** @var array<int, mixed> offset => event (offsets are never reused) */
    private array $log = [];

    private int $nextOffset = 0;

    /** @var array<string, int> group => offset of the next event it will read */
    private array $cursors = [];

    /** @return int the offset assigned to the event */
    public function append(mixed $event): int
    {
        $this->log[$this->nextOffset] = $event;

        return $this->nextOffset++;
    }

    /**
     * Read the next events for a group and advance its cursor.
     * Other groups are unaffected; they have their own cursor.
     *
     * @return list<mixed>
     */
    public function read(string $group, int $max = PHP_INT_MAX): array
    {
        $cursor = $this->cursors[$group] ?? $this->earliestOffset();

        if ($cursor < $this->earliestOffset()) {
            throw new OutOfRangeException("Group [{$group}] is at offset {$cursor}, but the log now starts at {$this->earliestOffset()}: events were lost to retention.");
        }

        $events = [];

        while ($cursor < $this->nextOffset && count($events) < $max) {
            $events[] = $this->log[$cursor++];
        }

        $this->cursors[$group] = $cursor;

        return $events;
    }

    /** Move a group's cursor, e.g. back to 0 to reprocess everything. */
    public function seek(string $group, int $offset): void
    {
        $this->cursors[$group] = $offset;
    }

    /** How many events a group still has to read. */
    public function lag(string $group): int
    {
        return $this->nextOffset - ($this->cursors[$group] ?? $this->earliestOffset());
    }

    /** Retention: drop the oldest events regardless of who has read them. */
    public function truncateBefore(int $offset): void
    {
        foreach (array_keys($this->log) as $existing) {
            if ($existing < $offset) {
                unset($this->log[$existing]);
            }
        }
    }

    public function earliestOffset(): int
    {
        return $this->log === [] ? $this->nextOffset : array_key_first($this->log);
    }

    public function size(): int
    {
        return count($this->log);
    }
}
