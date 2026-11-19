<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\VectorSearch;

use Random\Randomizer;

/**
 * HNSW: Hierarchical Navigable Small World graph (Malkov & Yashunin, 2016).
 *
 * Every vector is a node linked to a few of its nearest neighbours. Nodes
 * are also assigned to layers at random, with exponentially fewer nodes
 * per layer going up:
 *
 *   layer 2:   A ───────────────────── K            few nodes, long hops
 *   layer 1:   A ─────── E ─────────── K ──── P
 *   layer 0:   A ─ B ─ C ─ E ─ F ─ H ─ K ─ M ─ P    every node, short hops
 *
 * Search enters at the top and greedily moves to whichever neighbour is
 * closer to the query. When it can't get closer on that layer it drops
 * down one and continues from where it is. Only the bottom layer is
 * searched thoroughly.
 */
final class HnswIndex implements VectorIndex
{
    /** @var array<string, list<float>> */
    private array $vectors = [];

    /** @var array<int, array<string, list<string>>> layer => node => its neighbours on that layer */
    private array $layers = [];

    private ?string $entryPoint = null;

    private int $topLayer = -1;

    public int $distanceComputations = 0;

    private readonly Randomizer $random;

    /**
     * @param  int  $m  links created per node per layer (layer 0 keeps up to 2m)
     * @param  int  $efConstruction  candidates considered when linking a new node: higher = better graph, slower build
     * @param  int  $efSearch  candidates kept while searching the bottom layer: higher = better recall, slower query
     */
    public function __construct(
        private readonly int $m = 8,
        private readonly int $efConstruction = 48,
        private int $efSearch = 32,
        ?Randomizer $random = null,
    ) {
        $this->random = $random ?? new Randomizer;
    }

    public function add(string $id, array $vector): void
    {
        $this->vectors[$id] = $vector;
        $level = $this->randomLevel();

        if ($this->entryPoint === null) {
            for ($layer = 0; $layer <= $level; $layer++) {
                $this->layers[$layer][$id] = [];
            }

            $this->entryPoint = $id;
            $this->topLayer = $level;

            return;
        }

        $entryPoints = [$this->entryPoint];

        // Phase 1: above the new node's top layer, just find a good place to enter.
        for ($layer = $this->topLayer; $layer > $level; $layer--) {
            $entryPoints = [array_key_first($this->searchLayer($vector, $entryPoints, 1, $layer))];
        }

        // Phase 2: on each layer the node belongs to, link it to its nearest neighbours.
        for ($layer = min($level, $this->topLayer); $layer >= 0; $layer--) {
            $candidates = $this->searchLayer($vector, $entryPoints, $this->efConstruction, $layer);
            $neighbours = array_slice(array_keys($candidates), 0, $this->m);

            $this->layers[$layer][$id] = $neighbours;

            foreach ($neighbours as $neighbour) {
                $this->layers[$layer][$neighbour][] = $id; // links go both ways
                $this->trimLinks($neighbour, $layer);
            }

            $entryPoints = array_keys($candidates);
        }

        // The new node reaches higher than anything before it: it becomes the entry point.
        if ($level > $this->topLayer) {
            for ($layer = $this->topLayer + 1; $layer <= $level; $layer++) {
                $this->layers[$layer][$id] = [];
            }

            $this->topLayer = $level;
            $this->entryPoint = $id;
        }
    }

    public function search(array $query, int $k): array
    {
        if ($this->entryPoint === null) {
            return [];
        }

        $entryPoints = [$this->entryPoint];

        // Coarse navigation: one greedy step per upper layer.
        for ($layer = $this->topLayer; $layer > 0; $layer--) {
            $entryPoints = [array_key_first($this->searchLayer($query, $entryPoints, 1, $layer))];
        }

        // Fine search on the bottom layer.
        $found = $this->searchLayer($query, $entryPoints, max($this->efSearch, $k), 0);

        return array_slice($found, 0, $k, preserve_keys: true);
    }

    public function setEfSearch(int $efSearch): void
    {
        $this->efSearch = $efSearch;
    }

    public function layerCount(): int
    {
        return $this->topLayer + 1;
    }

    /**
     * Best-first search within one layer.
     *
     * Keeps the $ef closest nodes seen so far and keeps expanding the
     * closest unexplored one, until nothing unexplored could improve the result.
     *
     * @param  list<float>  $query
     * @param  list<string>  $entryPoints
     * @return array<string, float> up to $ef node ids => distance, nearest first
     */
    private function searchLayer(array $query, array $entryPoints, int $ef, int $layer): array
    {
        $visited = [];
        $toExplore = [];
        $best = [];

        foreach ($entryPoints as $node) {
            $visited[$node] = true;
            $toExplore[$node] = $best[$node] = $this->distanceTo($query, $node);
        }

        while ($toExplore !== []) {
            $current = array_search(min($toExplore), $toExplore, true);
            $currentDistance = $toExplore[$current];
            unset($toExplore[$current]);

            // Every remaining candidate is further than the worst result we hold: done.
            if (count($best) >= $ef && $currentDistance > max($best)) {
                break;
            }

            foreach ($this->layers[$layer][$current] ?? [] as $neighbour) {
                if (isset($visited[$neighbour])) {
                    continue;
                }

                $visited[$neighbour] = true;
                $distance = $this->distanceTo($query, $neighbour);

                if (count($best) < $ef || $distance < max($best)) {
                    $toExplore[$neighbour] = $best[$neighbour] = $distance;

                    if (count($best) > $ef) {
                        unset($best[array_search(max($best), $best, true)]); // drop the furthest
                    }
                }
            }
        }

        asort($best);

        return $best;
    }

    /** A node may only keep so many links; when it has too many, keep the closest ones. */
    private function trimLinks(string $node, int $layer): void
    {
        $limit = $layer === 0 ? $this->m * 2 : $this->m;
        $links = $this->layers[$layer][$node];

        if (count($links) <= $limit) {
            return;
        }

        $distances = [];

        foreach ($links as $link) {
            $distances[$link] = $this->distanceTo($this->vectors[$node], $link);
        }

        asort($distances);

        $this->layers[$layer][$node] = array_slice(array_keys($distances), 0, $limit);
    }

    /**
     * Draw the node's top layer from an exponential distribution: most
     * nodes get 0, a fraction get 1, a fraction of those get 2, and so on.
     */
    private function randomLevel(): int
    {
        $uniform = max($this->random->nextFloat(), PHP_FLOAT_MIN);

        return (int) floor(-log($uniform) / log($this->m));
    }

    /** @param list<float> $query */
    private function distanceTo(array $query, string $node): float
    {
        $this->distanceComputations++;

        return Distance::euclidean($query, $this->vectors[$node]);
    }
}
