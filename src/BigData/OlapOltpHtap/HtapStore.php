<?php

declare(strict_types=1);

namespace Patterns\BigData\OlapOltpHtap;

/**
 * HTAP: one system, two layouts of the same data.
 *
 *   transactions ──► ROW STORE ──┬──► delta (recent changes, in memory)
 *                                └──► COLUMN STORE (merged in the background)
 *   analytics    ──► COLUMN STORE + delta
 *
 * Point lookups and writes use the row store. Aggregations use the column
 * store, and add the not-yet-merged delta on top so that they include even
 * the most recent transaction. There is no nightly ETL and no stale warehouse.
 */
final class HtapStore
{
    public readonly RowStore $rows;

    public readonly ColumnStore $columns;

    /** @var array<int, array<string, mixed>> rows written since the last merge */
    private array $delta = [];

    public function __construct()
    {
        $this->rows = new RowStore;
        $this->columns = new ColumnStore;
    }

    /** The transactional path: as cheap as a plain row store. */
    public function insert(int $id, array $row): void
    {
        $this->rows->insert($id, $row);
        $this->delta[$id] = $row;
    }

    /** OLTP query → row store. */
    public function find(int $id): ?array
    {
        return $this->rows->find($id);
    }

    /**
     * OLAP query → column store, plus the delta for freshness.
     *
     * @param  bool  $includeDelta  false shows what a warehouse loaded by periodic ETL would answer
     */
    public function sum(string $column, bool $includeDelta = true): int|float
    {
        $total = $this->columns->sum($column);

        if ($includeDelta) {
            $total += array_sum(array_column($this->delta, $column));
        }

        return $total;
    }

    /** Background job: fold the delta into the column store. */
    public function mergeDelta(): int
    {
        $merged = count($this->delta);

        foreach ($this->delta as $id => $row) {
            $this->columns->insert($id, $row);
        }

        $this->delta = [];

        return $merged;
    }

    public function pendingDelta(): int
    {
        return count($this->delta);
    }
}
