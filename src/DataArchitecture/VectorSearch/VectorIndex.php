<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\VectorSearch;

interface VectorIndex
{
    /** @param list<float> $vector */
    public function add(string $id, array $vector): void;

    /**
     * The (approximately) $k nearest stored vectors.
     *
     * @param  list<float>  $query
     * @return array<string, float> id => distance, nearest first
     */
    public function search(array $query, int $k): array;
}
