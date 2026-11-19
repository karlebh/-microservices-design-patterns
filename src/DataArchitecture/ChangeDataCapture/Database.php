<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ChangeDataCapture;

use Closure;

/**
 * A minimal transactional store with a transaction log.
 *
 * It models the one property CDC depends on: every COMMITTED change — and
 * nothing else — is appended to an ordered log, as part of the commit.
 * (In Postgres this is the WAL, in MySQL the binlog.)
 */
final class Database
{
    /** @var array<string, array<string|int, array>> table => primary key => row */
    private array $tables = [];

    /** @var list<ChangeEvent> */
    private array $log = [];

    private int $nextTransactionId = 1;

    /**
     * Run $work atomically. If it throws, none of its changes are applied
     * and none reach the log.
     *
     * @param  Closure(Transaction): mixed  $work
     */
    public function transaction(Closure $work): mixed
    {
        $transaction = new Transaction($this->tables);

        $result = $work($transaction); // an exception here == rollback: we simply never commit

        $this->commit($transaction);

        return $result;
    }

    private function commit(Transaction $transaction): void
    {
        $transactionId = $this->nextTransactionId++;

        $this->tables = $transaction->tables();

        foreach ($transaction->changes() as $change) {
            $this->log[] = new ChangeEvent(
                lsn: count($this->log) + 1,
                transactionId: $transactionId,
                table: $change['table'],
                op: $change['op'],
                key: $change['key'],
                before: $change['before'],
                after: $change['after'],
            );
        }
    }

    public function find(string $table, string|int $key): ?array
    {
        return $this->tables[$table][$key] ?? null;
    }

    /** @return array<string|int, array> */
    public function rows(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    /** Position of the newest log entry. */
    public function lsn(): int
    {
        return count($this->log);
    }

    /**
     * The replication stream: committed changes after the given position.
     *
     * @return list<ChangeEvent>
     */
    public function logAfter(int $lsn): array
    {
        return array_slice($this->log, $lsn);
    }
}
