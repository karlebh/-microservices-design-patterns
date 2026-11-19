<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\EventSourcing;

/**
 * An append-only event store (in memory).
 *
 * Two views of the same data:
 *  - per STREAM (one aggregate's history), used to rebuild that aggregate
 *  - one GLOBAL log (all events in commit order), used by projections
 *
 * There is deliberately no update() and no delete().
 */
final class EventStore
{
    /** @var array<string, list<DomainEvent>> */
    private array $streams = [];

    /** @var list<array{position: int, stream: string, version: int, event: DomainEvent}> */
    private array $log = [];

    /**
     * Append events to a stream, but only if nobody else got there first.
     *
     * $expectedVersion is the number of events the caller saw when it loaded
     * the stream. A mismatch means the caller made its decision on stale
     * state, so the write is refused (optimistic concurrency control).
     *
     * @param  list<DomainEvent>  $events
     */
    public function append(string $stream, int $expectedVersion, array $events): void
    {
        $actualVersion = $this->version($stream);

        if ($actualVersion !== $expectedVersion) {
            throw ConcurrencyException::versionMismatch($stream, $expectedVersion, $actualVersion);
        }

        foreach ($events as $event) {
            $this->streams[$stream][] = $event;

            $this->log[] = [
                'position' => count($this->log) + 1,
                'stream' => $stream,
                'version' => count($this->streams[$stream]),
                'event' => $event,
            ];
        }
    }

    /**
     * @param  int  $afterVersion  skip the first N events (used when starting from a snapshot)
     * @param  int|null  $untilVersion  stop at this version (used for time travel)
     * @return list<DomainEvent>
     */
    public function load(string $stream, int $afterVersion = 0, ?int $untilVersion = null): array
    {
        $length = $untilVersion === null ? null : max(0, $untilVersion - $afterVersion);

        return array_slice($this->streams[$stream] ?? [], $afterVersion, $length);
    }

    public function version(string $stream): int
    {
        return count($this->streams[$stream] ?? []);
    }

    /**
     * Every event of every stream, in the order they were committed.
     *
     * @return list<array{position: int, stream: string, version: int, event: DomainEvent}>
     */
    public function all(int $afterPosition = 0): array
    {
        return array_slice($this->log, $afterPosition);
    }
}
