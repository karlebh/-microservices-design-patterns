<?php

declare(strict_types=1);

namespace Patterns\BigData\OlapOltpHtap;

/**
 * Column-oriented storage (OLAP): each column is stored as one unit.
 *
 * Aggregating a column reads that column and nothing else. Anything that
 * needs a whole row has to visit every column.
 */
final class ColumnStore
{
    /** @var array<string, array<int, mixed>> column => id => value */
    private array $columns = [];

    public int $seeks = 0;

    public int $cellsRead = 0;

    public function insert(int $id, array $row): void
    {
        foreach ($row as $column => $value) {
            $this->columns[$column][$id] = $value;
            $this->seeks++; // a separate write per column
        }
    }

    /** Reassembling one row means one lookup in each column. */
    public function find(int $id): ?array
    {
        $row = [];

        foreach ($this->columns as $column => $values) {
            $this->seeks++;
            $this->cellsRead++;

            if (array_key_exists($id, $values)) {
                $row[$column] = $values[$id];
            }
        }

        return $row === [] ? null : $row;
    }

    /** Aggregate one column: a single sequential read of just that column. */
    public function sum(string $column): int|float
    {
        $this->seeks++;
        $this->cellsRead += count($this->columns[$column] ?? []);

        return array_sum($this->columns[$column] ?? []);
    }

    /**
     * Run-length encoding: store "value, how many times in a row" instead
     * of repeating the value. Extremely effective on sorted, low-cardinality
     * columns (country, status, date).
     *
     * @return list<array{value: mixed, run: int}>
     */
    public function runLengthEncode(string $column): array
    {
        $runs = [];

        foreach ($this->columns[$column] ?? [] as $value) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['value'] === $value) {
                $runs[$last]['run']++;
            } else {
                $runs[] = ['value' => $value, 'run' => 1];
            }
        }

        return $runs;
    }

    /**
     * Dictionary encoding: replace each (long) value by a small integer
     * code, and store each distinct value once.
     *
     * @return array{dictionary: list<mixed>, codes: list<int>}
     */
    public function dictionaryEncode(string $column): array
    {
        $dictionary = array_values(array_unique($this->columns[$column] ?? []));
        $codeOf = array_flip($dictionary);

        return [
            'dictionary' => $dictionary,
            'codes' => array_map(fn (mixed $value) => $codeOf[$value], array_values($this->columns[$column] ?? [])),
        ];
    }

    public function count(): int
    {
        return $this->columns === [] ? 0 : count(reset($this->columns));
    }
}
