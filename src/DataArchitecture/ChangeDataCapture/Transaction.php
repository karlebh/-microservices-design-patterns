<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ChangeDataCapture;

use LogicException;

/**
 * A unit of work against the Database. Changes are made on a private copy
 * and recorded; the Database applies and logs them only on commit.
 */
final class Transaction
{
    /** @var list<array{table: string, op: string, key: string|int, before: ?array, after: ?array}> */
    private array $changes = [];

    /** @param array<string, array<string|int, array>> $tables private working copy of the data */
    public function __construct(private array $tables) {}

    public function insert(string $table, string|int $key, array $row): void
    {
        if (isset($this->tables[$table][$key])) {
            throw new LogicException("Duplicate key [{$key}] in table [{$table}].");
        }

        $this->tables[$table][$key] = $row;
        $this->changes[] = ['table' => $table, 'op' => ChangeEvent::CREATE, 'key' => $key, 'before' => null, 'after' => $row];
    }

    public function update(string $table, string|int $key, array $changes): void
    {
        $before = $this->tables[$table][$key] ?? throw new LogicException("No row [{$key}] in table [{$table}].");
        $after = array_merge($before, $changes);

        $this->tables[$table][$key] = $after;
        $this->changes[] = ['table' => $table, 'op' => ChangeEvent::UPDATE, 'key' => $key, 'before' => $before, 'after' => $after];
    }

    public function delete(string $table, string|int $key): void
    {
        $before = $this->tables[$table][$key] ?? throw new LogicException("No row [{$key}] in table [{$table}].");

        unset($this->tables[$table][$key]);
        $this->changes[] = ['table' => $table, 'op' => ChangeEvent::DELETE, 'key' => $key, 'before' => $before, 'after' => null];
    }

    /** Reads see this transaction's own uncommitted writes. */
    public function find(string $table, string|int $key): ?array
    {
        return $this->tables[$table][$key] ?? null;
    }

    /** @internal used by Database on commit */
    public function changes(): array
    {
        return $this->changes;
    }

    /** @internal used by Database on commit */
    public function tables(): array
    {
        return $this->tables;
    }
}
