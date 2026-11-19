<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\MaterializedViews;

/**
 * A base table that tells its materialized views about every change, the
 * way a database feeds its change stream to incremental views.
 */
final class Table
{
    /** @var array<int, array<string, mixed>> id => row */
    private array $rows = [];

    /** @var list<MaterializedView> */
    private array $incrementalViews = [];

    private int $nextId = 1;

    /** Keep this view up to date automatically from now on. */
    public function maintain(MaterializedView $view): void
    {
        $view->fullRefresh($this->rows);

        $this->incrementalViews[] = $view;
    }

    public function insert(array $row): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = $row;

        foreach ($this->incrementalViews as $view) {
            $view->onInsert($row);
        }

        return $id;
    }

    public function update(int $id, array $changes): void
    {
        $old = $this->rows[$id];
        $new = array_merge($old, $changes);

        $this->rows[$id] = $new;

        foreach ($this->incrementalViews as $view) {
            $view->onUpdate($old, $new);
        }
    }

    public function delete(int $id): void
    {
        $old = $this->rows[$id];
        unset($this->rows[$id]);

        foreach ($this->incrementalViews as $view) {
            $view->onDelete($old);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_keys($this->rows);
    }
}
