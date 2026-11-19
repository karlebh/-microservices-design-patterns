<?php

declare(strict_types=1);

namespace Patterns\BigData\KappaVsLambda;

/**
 * The immutable master dataset: every event ever received, in arrival
 * order, never changed. Both architectures treat it as the source of truth
 * from which any view can be (re)derived.
 */
final class EventLog
{
    /** @var list<array{id: string, page: string, bot: bool}> */
    private array $events = [];

    /** @param array{id: string, page: string, bot?: bool} $event */
    public function append(array $event): int
    {
        $this->events[] = $event + ['bot' => false];

        return count($this->events) - 1;
    }

    /**
     * @return list<array{id: string, page: string, bot: bool}> everything from $offset onwards
     */
    public function readFrom(int $offset = 0): array
    {
        return array_slice($this->events, $offset);
    }

    public function size(): int
    {
        return count($this->events);
    }
}
