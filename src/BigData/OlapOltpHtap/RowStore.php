<?php

declare(strict_types=1);

namespace Patterns\BigData\OlapOltpHtap;

/**
 * Row-oriented storage (OLTP): each row is stored as one unit.
 *
 * Counters model the I/O:
 *   seeks      - separate places on disk that had to be visited
 *   cellsRead  - individual values that had to be loaded
 */
final class RowStore
{
    /** @var array<int, array<string, mixed>> id => the whole row */
    private array $rows = [];

    public int $seeks = 0;

    public int $cellsRead = 0;

    public function insert(int $id, array $row): void
    {
        $this->rows[$id] = $row;
        $this->seeks++; // one row, one place
    }

    /** Point lookup: go to the row, read it. */
    public function find(int $id): ?array
    {
        $row = $this->rows[$id] ?? null;

        $this->seeks++;
        $this->cellsRead += count($row ?? []);

        return $row;
    }

    public function update(int $id, string $column, mixed $value): void
    {
        $this->rows[$id][$column] = $value;
        $this->seeks++;
    }

    /**
     * Aggregate one column. The values are scattered across every row, so
     * every row — with all its other columns — has to be read.
     */
    public function sum(string $column): int|float
    {
        $total = 0;
        $this->seeks++; // one sequential scan

        foreach ($this->rows as $row) {
            $this->cellsRead += count($row);
            $total += $row[$column];
        }

        return $total;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
