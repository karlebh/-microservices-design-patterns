<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ChangeDataCapture;

use Closure;

/**
 * Tails the database's transaction log and hands each change to its sinks.
 *
 * The connector's entire state is its offset: the LSN of the last event the
 * sinks have accepted. It moves forward only after delivery succeeded, so:
 *
 *   crash / sink failure  ->  the event is delivered again  ->  at-least-once
 *
 * Nothing is lost, duplicates are possible, and order is the commit order.
 */
final class CdcConnector
{
    /** @var list<Closure(ChangeEvent): void> */
    private array $sinks = [];

    /**
     * @param  int  $offset  resume after this LSN (what a restarted connector loads from durable storage)
     * @param  list<string>  $tables  capture only these tables; empty means all
     */
    public function __construct(
        private readonly Database $database,
        private int $offset = 0,
        private readonly array $tables = [],
    ) {}

    public function addSink(Closure $sink): void
    {
        $this->sinks[] = $sink;
    }

    /**
     * Initial snapshot for a connector that starts on a database with
     * existing data: emit every current row as a READ event, then continue
     * streaming from the log position seen when the snapshot was taken.
     *
     * @return int number of rows emitted
     */
    public function snapshot(): int
    {
        $positionAtSnapshot = $this->database->lsn();
        $emitted = 0;

        foreach ($this->database->tableNames() as $table) {
            if (! $this->captures($table)) {
                continue;
            }

            foreach ($this->database->rows($table) as $key => $row) {
                $this->deliver(new ChangeEvent($positionAtSnapshot, 0, $table, ChangeEvent::READ, $key, null, $row));
                $emitted++;
            }
        }

        $this->offset = $positionAtSnapshot;

        return $emitted;
    }

    /**
     * Ship changes committed since the last poll.
     *
     * @return int number of events delivered
     */
    public function poll(): int
    {
        $delivered = 0;

        foreach ($this->database->logAfter($this->offset) as $event) {
            if ($this->captures($event->table)) {
                $this->deliver($event); // if a sink throws, we stop here and retry this event next time
                $delivered++;
            }

            $this->offset = $event->lsn;
        }

        return $delivered;
    }

    /** The position to persist. A new connector started with it continues where this one stopped. */
    public function offset(): int
    {
        return $this->offset;
    }

    private function deliver(ChangeEvent $event): void
    {
        foreach ($this->sinks as $sink) {
            $sink($event);
        }
    }

    private function captures(string $table): bool
    {
        return $this->tables === [] || in_array($table, $this->tables, true);
    }
}
