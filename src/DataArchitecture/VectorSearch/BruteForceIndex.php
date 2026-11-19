<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\VectorSearch;

/**
 * Exact nearest-neighbour search: compare the query with every vector.
 *
 * Always correct, and O(n) per query. It is the right choice for small
 * collections, and it is the ground truth that approximate indexes are
 * measured against.
 */
final class BruteForceIndex implements VectorIndex
{
    /** @var array<string, list<float>> */
    private array $vectors = [];

    public int $distanceComputations = 0;

    public function add(string $id, array $vector): void
    {
        $this->vectors[$id] = $vector;
    }

    public function search(array $query, int $k): array
    {
        $distances = [];

        foreach ($this->vectors as $id => $vector) {
            $distances[$id] = Distance::euclidean($query, $vector);
            $this->distanceComputations++;
        }

        asort($distances);

        return array_slice($distances, 0, $k, preserve_keys: true);
    }
}
