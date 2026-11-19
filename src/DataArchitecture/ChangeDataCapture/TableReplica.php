<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ChangeDataCapture;

/**
 * A sink that keeps a copy of one table up to date from change events.
 *
 * Think "search index" or "cache": a derived store that the application
 * never writes to directly. Applying an event is idempotent (it sets the
 * row to the event's `after` image), so redelivery is harmless.
 */
final class TableReplica
{
    /** @var array<string|int, array> */
    private array $rows = [];

    public function __construct(private readonly string $table) {}

    public function apply(ChangeEvent $event): void
    {
        if ($event->table !== $this->table) {
            return;
        }

        if ($event->op === ChangeEvent::DELETE) {
            unset($this->rows[$event->key]);

            return;
        }

        // create, update and snapshot-read all mean: "this is the row now".
        $this->rows[$event->key] = $event->after;
    }

    /** @return array<string|int, array> */
    public function rows(): array
    {
        return $this->rows;
    }
}
