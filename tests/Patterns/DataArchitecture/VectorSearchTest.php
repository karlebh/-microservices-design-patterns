<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\VectorSearch\BruteForceIndex;
use Patterns\DataArchitecture\VectorSearch\Distance;
use Patterns\DataArchitecture\VectorSearch\HnswIndex;
use Patterns\DataArchitecture\VectorSearch\IvfFlatIndex;
use Patterns\DataArchitecture\VectorSearch\VectorIndex;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class VectorSearchTest extends TestCase
{
    private const DIMENSIONS = 8;

    private const K = 10;

    /** @return list<float> */
    private function randomVector(Randomizer $random): array
    {
        return array_map(fn () => $random->getFloat(-1.0, 1.0), range(1, self::DIMENSIONS));
    }

    /**
     * Points gathered around a number of centres, the way real embeddings
     * cluster by topic.
     *
     * @return array<string, list<float>> id => vector
     */
    private function clusteredVectors(int $clusters, int $perCluster, Randomizer $random): array
    {
        $vectors = [];

        for ($c = 0; $c < $clusters; $c++) {
            $centre = $this->randomVector($random);

            for ($i = 0; $i < $perCluster; $i++) {
                $vectors[sprintf('doc-%02d-%03d', $c, $i)] = array_map(
                    fn (float $x) => $x + $random->getFloat(-0.08, 0.08),
                    $centre,
                );
            }
        }

        return $vectors;
    }

    /**
     * Average share of the true nearest neighbours that the index found.
     *
     * @param  list<list<float>>  $queries
     */
    private function recall(VectorIndex $index, BruteForceIndex $exact, array $queries): float
    {
        $total = 0.0;

        foreach ($queries as $query) {
            $truth = array_keys($exact->search($query, self::K));
            $found = array_keys($index->search($query, self::K));

            $total += count(array_intersect($truth, $found)) / self::K;
        }

        return $total / count($queries);
    }

    // ---- Distances ----------------------------------------------------------------------

    public function test_distance_measures(): void
    {
        $this->assertSame(5.0, Distance::euclidean([0.0, 0.0], [3.0, 4.0]));

        $this->assertEqualsWithDelta(1.0, Distance::cosineSimilarity([1.0, 2.0], [10.0, 20.0]), 1e-9, 'Same direction: identical meaning, whatever the length.');
        $this->assertEqualsWithDelta(0.0, Distance::cosineSimilarity([1.0, 0.0], [0.0, 1.0]), 1e-9, 'Perpendicular: unrelated.');
        $this->assertEqualsWithDelta(-1.0, Distance::cosineSimilarity([1.0, 0.0], [-1.0, 0.0]), 1e-9, 'Opposite.');
    }

    // ---- Exact search -------------------------------------------------------------------

    public function test_brute_force_returns_the_true_nearest_neighbours_in_order(): void
    {
        $index = new BruteForceIndex;
        $index->add('near', [1.0, 1.0]);
        $index->add('nearer', [0.5, 0.5]);
        $index->add('far', [9.0, 9.0]);
        $index->add('nearest', [0.1, 0.0]);

        $this->assertSame(['nearest', 'nearer', 'near'], array_keys($index->search([0.0, 0.0], 3)));
        $this->assertSame(4, $index->distanceComputations, 'It had to look at every vector.');
    }

    // ---- IVF-Flat -----------------------------------------------------------------------

    /** @return array{IvfFlatIndex, BruteForceIndex, list<list<float>>, int} */
    private function ivfOverClusters(): array
    {
        $random = new Randomizer(new Mt19937(21));
        $vectors = $this->clusteredVectors(clusters: 12, perCluster: 50, random: $random);

        $ivf = new IvfFlatIndex(nlist: 12, nprobe: 1, random: new Randomizer(new Mt19937(4)));
        $ivf->train(array_values($vectors));
        $exact = new BruteForceIndex;

        foreach ($vectors as $id => $vector) {
            $ivf->add($id, $vector);
            $exact->add($id, $vector);
        }

        // Queries are slight variations of existing documents.
        $queries = array_map(
            fn (array $vector) => array_map(fn (float $x) => $x + 0.01, $vector),
            array_slice(array_values($vectors), 0, 600, false),
        );

        return [$ivf, $exact, array_values(array_filter($queries, fn ($_, int $i) => $i % 20 === 0, ARRAY_FILTER_USE_BOTH)), count($vectors)];
    }

    public function test_ivf_searches_a_fraction_of_the_vectors_and_still_finds_most_neighbours(): void
    {
        [$ivf, $exact, $queries, $total] = $this->ivfOverClusters();

        $ivf->distanceComputations = 0;
        $recall = $this->recall($ivf, $exact, $queries);
        $perQuery = $ivf->distanceComputations / count($queries);

        $this->assertGreaterThan(0.8, $recall, 'Probing a single cell already finds most true neighbours.');
        $this->assertLessThan($total / 4, $perQuery, 'And it compared the query with well under a quarter of the collection.');
    }

    public function test_ivf_probing_every_cell_is_exact(): void
    {
        [$ivf, $exact, $queries] = $this->ivfOverClusters();

        $ivf->probe(12);

        $this->assertSame(1.0, $this->recall($ivf, $exact, $queries), 'nprobe = nlist degenerates into a full scan.');
    }

    public function test_ivf_recall_improves_as_more_cells_are_probed(): void
    {
        $random = new Randomizer(new Mt19937(8));

        // Uniformly scattered data has no natural clusters, so neighbours are often split across cells.
        $ivf = new IvfFlatIndex(nlist: 16, nprobe: 1, random: new Randomizer(new Mt19937(4)));
        $exact = new BruteForceIndex;
        $vectors = array_map(fn () => $this->randomVector($random), range(1, 600));

        $ivf->train($vectors);

        foreach ($vectors as $i => $vector) {
            $ivf->add("v{$i}", $vector);
            $exact->add("v{$i}", $vector);
        }

        $queries = array_map(fn () => $this->randomVector($random), range(1, 25));

        $ivf->probe(1);
        $narrow = $this->recall($ivf, $exact, $queries);

        $ivf->probe(8);
        $wide = $this->recall($ivf, $exact, $queries);

        $this->assertGreaterThan($narrow + 0.2, $wide, 'nprobe is the speed/recall dial.');
    }

    // ---- HNSW ---------------------------------------------------------------------------

    /** @return array{HnswIndex, BruteForceIndex, list<list<float>>} */
    private function hnswOverRandomVectors(int $count = 600): array
    {
        $random = new Randomizer(new Mt19937(13));

        $hnsw = new HnswIndex(m: 8, efConstruction: 48, efSearch: 40, random: new Randomizer(new Mt19937(99)));
        $exact = new BruteForceIndex;

        for ($i = 0; $i < $count; $i++) {
            $vector = $this->randomVector($random);

            $hnsw->add("v{$i}", $vector);
            $exact->add("v{$i}", $vector);
        }

        return [$hnsw, $exact, array_map(fn () => $this->randomVector($random), range(1, 25))];
    }

    public function test_hnsw_finds_nearly_all_true_neighbours_with_a_fraction_of_the_work(): void
    {
        [$hnsw, $exact, $queries] = $this->hnswOverRandomVectors();

        $hnsw->distanceComputations = 0;
        $recall = $this->recall($hnsw, $exact, $queries);
        $perQuery = $hnsw->distanceComputations / count($queries);

        $this->assertGreaterThan(0.9, $recall);
        $this->assertLessThan(300, $perQuery, 'Fewer than half of the 600 vectors were examined per query; the gap widens as the collection grows.');
    }

    public function test_hnsw_trades_recall_for_speed_with_ef_search(): void
    {
        [$hnsw, $exact, $queries] = $this->hnswOverRandomVectors();

        $measure = function (int $efSearch) use ($hnsw, $exact, $queries): array {
            $hnsw->setEfSearch($efSearch);
            $hnsw->distanceComputations = 0;

            return [$this->recall($hnsw, $exact, $queries), $hnsw->distanceComputations];
        };

        [$quickRecall, $quickWork] = $measure(10);
        [$thoroughRecall, $thoroughWork] = $measure(120);

        $this->assertGreaterThan($quickWork, $thoroughWork);
        $this->assertGreaterThanOrEqual($quickRecall, $thoroughRecall);
        $this->assertGreaterThan(0.97, $thoroughRecall);
    }

    public function test_hnsw_builds_a_hierarchy_with_few_nodes_at_the_top(): void
    {
        [$hnsw] = $this->hnswOverRandomVectors();

        $this->assertGreaterThanOrEqual(2, $hnsw->layerCount(), 'With m = 8 roughly one node in eight reaches each next layer.');
        $this->assertLessThanOrEqual(6, $hnsw->layerCount());
    }

    public function test_hnsw_returns_an_exact_match_first(): void
    {
        $hnsw = new HnswIndex(random: new Randomizer(new Mt19937(1)));
        $random = new Randomizer(new Mt19937(2));
        $vectors = [];

        for ($i = 0; $i < 200; $i++) {
            $hnsw->add("v{$i}", $vectors[$i] = $this->randomVector($random));
        }

        $result = $hnsw->search($vectors[123], 1);

        $this->assertSame('v123', array_key_first($result));
        $this->assertSame(0.0, $result['v123']);
    }
}
