<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\VectorSearch;

use LogicException;
use Random\Randomizer;

/**
 * IVF-Flat: an inverted file index.
 *
 * The vector space is cut into `nlist` cells, each represented by a
 * centroid found with k-means. A vector is filed under its nearest
 * centroid. A query only looks inside the `nprobe` cells whose centroids
 * are closest to it, and scans those exhaustively ("flat": the vectors are
 * stored uncompressed).
 *
 * The approximation: a true neighbour sitting just across a cell border
 * is missed unless that neighbouring cell is probed too.
 */
final class IvfFlatIndex implements VectorIndex
{
    /** @var list<list<float>> */
    private array $centroids = [];

    /** @var array<int, array<string, list<float>>> cell => id => vector */
    private array $lists = [];

    public int $distanceComputations = 0;

    private readonly Randomizer $random;

    /**
     * @param  int  $nlist  number of cells
     * @param  int  $nprobe  cells searched per query (1 = fastest, nlist = exact)
     */
    public function __construct(
        private readonly int $nlist,
        private int $nprobe = 1,
        ?Randomizer $random = null,
    ) {
        $this->random = $random ?? new Randomizer;
    }

    /**
     * Learn the centroids from a representative sample (k-means).
     *
     * @param  list<list<float>>  $sample
     */
    public function train(array $sample, int $iterations = 10): void
    {
        // Start from randomly chosen sample points.
        $this->centroids = array_map(
            fn (int|string $key) => $sample[$key],
            (array) $this->random->pickArrayKeys($sample, $this->nlist),
        );

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            // Assignment step: each point joins its nearest centroid.
            $members = array_fill(0, $this->nlist, []);

            foreach ($sample as $vector) {
                $members[$this->nearestCells($vector, 1)[0]][] = $vector;
            }

            // Update step: each centroid moves to the mean of its members.
            foreach ($members as $cell => $vectors) {
                if ($vectors !== []) {
                    $this->centroids[$cell] = $this->mean($vectors);
                }
            }
        }

        $this->lists = array_fill(0, $this->nlist, []);
    }

    public function add(string $id, array $vector): void
    {
        if ($this->centroids === []) {
            throw new LogicException('An IVF index must be trained before vectors are added.');
        }

        $this->lists[$this->nearestCells($vector, 1)[0]][$id] = $vector;
    }

    public function search(array $query, int $k): array
    {
        $distances = [];

        foreach ($this->nearestCells($query, $this->nprobe) as $cell) {
            foreach ($this->lists[$cell] as $id => $vector) {
                $distances[$id] = Distance::euclidean($query, $vector);
                $this->distanceComputations++;
            }
        }

        asort($distances);

        return array_slice($distances, 0, $k, preserve_keys: true);
    }

    /** Trade speed for recall at query time, without rebuilding anything. */
    public function probe(int $nprobe): void
    {
        $this->nprobe = $nprobe;
    }

    /**
     * The $count cells whose centroids are closest to the vector.
     *
     * @param  list<float>  $vector
     * @return list<int>
     */
    private function nearestCells(array $vector, int $count): array
    {
        $distances = [];

        foreach ($this->centroids as $cell => $centroid) {
            $distances[$cell] = Distance::euclidean($vector, $centroid);
            $this->distanceComputations++;
        }

        asort($distances);

        return array_slice(array_keys($distances), 0, $count);
    }

    /**
     * @param  list<list<float>>  $vectors
     * @return list<float>
     */
    private function mean(array $vectors): array
    {
        $sum = array_fill(0, count($vectors[0]), 0.0);

        foreach ($vectors as $vector) {
            foreach ($vector as $dimension => $value) {
                $sum[$dimension] += $value;
            }
        }

        return array_map(fn (float $total) => $total / count($vectors), $sum);
    }
}
