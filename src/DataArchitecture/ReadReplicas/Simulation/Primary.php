<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ReadReplicas\Simulation;

/**
 * The single node that accepts writes.
 *
 * Every write is applied locally AND appended to a replication log with an
 * increasing log sequence number (LSN). Replicas reproduce the primary by
 * replaying that log in order.
 */
final class Primary
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var list<array{lsn: int, op: string, key: string, value: mixed}> */
    private array $log = [];

    /** @return int the LSN of this write */
    public function write(string $key, mixed $value): int
    {
        $this->data[$key] = $value;

        return $this->append('set', $key, $value);
    }

    public function delete(string $key): int
    {
        unset($this->data[$key]);

        return $this->append('delete', $key, null);
    }

    public function read(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** Position of the newest write. */
    public function lsn(): int
    {
        return count($this->log);
    }

    /**
     * The replication stream: every change after the given position.
     *
     * @return list<array{lsn: int, op: string, key: string, value: mixed}>
     */
    public function changesAfter(int $lsn, ?int $limit = null): array
    {
        return array_slice($this->log, $lsn, $limit);
    }

    private function append(string $op, string $key, mixed $value): int
    {
        $lsn = count($this->log) + 1;
        $this->log[] = ['lsn' => $lsn, 'op' => $op, 'key' => $key, 'value' => $value];

        return $lsn;
    }
}
