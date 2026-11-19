<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\MaterializedViews;

use Closure;

/**
 * The stored result of
 *
 *   SELECT group, COUNT(*), SUM(value), AVG(value), MIN(value), MAX(value)
 *   FROM   table
 *   WHERE  <optional predicate>
 *   GROUP  BY group
 *
 * It can be brought up to date in two ways: by recomputing everything
 * (fullRefresh), or by applying individual changes (onInsert / onUpdate /
 * onDelete). Both must always produce the same answer.
 */
final class MaterializedView
{
    /**
     * Per group: the row count, the running sum, and how many rows have
     * each value (needed to answer MIN and MAX after deletions).
     *
     * @var array<string, array{count: int, sum: int|float, values: array<int|string, int>}>
     */
    private array $groups = [];

    /** How many base-table rows this view has had to look at. The cost of keeping it fresh. */
    public int $rowsProcessed = 0;

    /**
     * @param  Closure(array): string  $groupBy
     * @param  Closure(array): (int|float)  $value
     * @param  (Closure(array): bool)|null  $where
     */
    public function __construct(
        private readonly Closure $groupBy,
        private readonly Closure $value,
        private readonly ?Closure $where = null,
    ) {}

    /** Throw the stored result away and rebuild it from every row. */
    public function fullRefresh(iterable $rows): void
    {
        $this->groups = [];

        foreach ($rows as $row) {
            $this->apply($row, +1);
        }
    }

    public function onInsert(array $row): void
    {
        $this->apply($row, +1);
    }

    public function onDelete(array $row): void
    {
        $this->apply($row, -1);
    }

    /** An update is the old row leaving and the new row arriving. */
    public function onUpdate(array $old, array $new): void
    {
        $this->apply($old, -1);
        $this->apply($new, +1);
    }

    /** @return array{count: int, sum: int|float, avg: float, min: int|float, max: int|float}|null */
    public function get(string $group): ?array
    {
        $state = $this->groups[$group] ?? null;

        if ($state === null) {
            return null;
        }

        return [
            'count' => $state['count'],
            'sum' => $state['sum'],
            'avg' => $state['sum'] / $state['count'],
            'min' => min(array_keys($state['values'])),
            'max' => max(array_keys($state['values'])),
        ];
    }

    /** @return array<string, array{count: int, sum: int|float, avg: float, min: int|float, max: int|float}> sorted by group */
    public function all(): array
    {
        $result = [];

        foreach (array_keys($this->groups) as $group) {
            $result[(string) $group] = $this->get((string) $group);
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Add (+1) or retract (-1) one row's contribution.
     *
     * COUNT and SUM are adjusted arithmetically. For MIN/MAX the view keeps
     * a count per distinct value, so removing the current minimum simply
     * reveals the next one.
     */
    private function apply(array $row, int $weight): void
    {
        $this->rowsProcessed++;

        if ($this->where !== null && ! ($this->where)($row)) {
            return; // the row is not part of the view, before or after
        }

        $group = ($this->groupBy)($row);
        $value = ($this->value)($row);

        $state = $this->groups[$group] ?? ['count' => 0, 'sum' => 0, 'values' => []];

        $state['count'] += $weight;
        $state['sum'] += $weight * $value;
        $state['values'][$value] = ($state['values'][$value] ?? 0) + $weight;

        if ($state['values'][$value] === 0) {
            unset($state['values'][$value]);
        }

        if ($state['count'] === 0) {
            unset($this->groups[$group]); // the last row of the group is gone

            return;
        }

        $this->groups[$group] = $state;
    }
}
