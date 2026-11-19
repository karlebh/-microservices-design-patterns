<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ReadReplicas\Simulation;

/**
 * CQRS-driven synchronization: a read model fed by the replication stream.
 *
 * A replica is a byte-for-byte copy. This is not: it consumes the same log
 * but keeps the data in the shape one specific query wants ("how many tasks
 * are open / done?"), so that query is a lookup instead of a table scan.
 *
 * Like a replica, it is eventually consistent and can always be rebuilt by
 * replaying the log from position 0.
 */
final class TaskStatsProjection
{
    private int $appliedLsn = 0;

    /** @var array<string, bool> task key => completed? (needed to undo the old value on update/delete) */
    private array $completedByTask = [];

    private int $open = 0;

    private int $done = 0;

    public function __construct(private readonly Primary $primary) {}

    public function sync(): void
    {
        foreach ($this->primary->changesAfter($this->appliedLsn) as $change) {
            $key = $change['key'];

            // Remove the task's previous contribution, if it had one.
            if (isset($this->completedByTask[$key])) {
                $this->completedByTask[$key] ? $this->done-- : $this->open--;
                unset($this->completedByTask[$key]);
            }

            if ($change['op'] === 'set') {
                $completed = (bool) ($change['value']['completed'] ?? false);
                $this->completedByTask[$key] = $completed;
                $completed ? $this->done++ : $this->open++;
            }

            $this->appliedLsn = $change['lsn'];
        }
    }

    /** @return array{open: int, done: int} */
    public function stats(): array
    {
        return ['open' => $this->open, 'done' => $this->done];
    }
}
