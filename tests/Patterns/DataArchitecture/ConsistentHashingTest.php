<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\ConsistentHashing\HashRing;
use PHPUnit\Framework\TestCase;

final class ConsistentHashingTest extends TestCase
{
    /** @return list<string> */
    private function keys(int $count = 5_000): array
    {
        return array_map(fn (int $i) => "session:{$i}", range(1, $count));
    }

    /** @param list<string> $nodes */
    private function ring(array $nodes, int $virtualNodes = 150): HashRing
    {
        $ring = new HashRing($virtualNodes);

        foreach ($nodes as $node) {
            $ring->addNode($node);
        }

        return $ring;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string> key => owner
     */
    private function owners(HashRing $ring, array $keys): array
    {
        return array_combine($keys, array_map($ring->nodeFor(...), $keys));
    }

    public function test_a_key_always_maps_to_the_same_node(): void
    {
        $ring = $this->ring(['cache-a', 'cache-b', 'cache-c']);

        $this->assertSame($ring->nodeFor('user:42'), $ring->nodeFor('user:42'));

        // Another process building the ring from the same node list agrees.
        $this->assertSame($ring->nodeFor('user:42'), $this->ring(['cache-c', 'cache-a', 'cache-b'])->nodeFor('user:42'));
    }

    public function test_modulo_hashing_moves_almost_everything_when_a_node_is_added(): void
    {
        $moved = 0;

        foreach ($this->keys() as $key) {
            if (crc32($key) % 4 !== crc32($key) % 5) {
                $moved++;
            }
        }

        $this->assertGreaterThan(0.75 * 5_000, $moved, 'Going from 4 to 5 nodes relocates about 80% of the keys.');
    }

    public function test_consistent_hashing_moves_only_about_one_nth_when_a_node_is_added(): void
    {
        $keys = $this->keys();
        $ring = $this->ring(['cache-a', 'cache-b', 'cache-c', 'cache-d']);
        $before = $this->owners($ring, $keys);

        $ring->addNode('cache-e');
        $after = $this->owners($ring, $keys);

        $moved = array_keys(array_diff_assoc($after, $before));

        $this->assertEqualsWithDelta(0.20, count($moved) / count($keys), 0.06, 'Roughly a fifth: the new node\'s fair share.');

        foreach ($moved as $key) {
            $this->assertSame('cache-e', $after[$key], 'Keys only ever move TO the new node, never between old ones.');
        }
    }

    public function test_removing_a_node_only_reassigns_that_nodes_keys(): void
    {
        $keys = $this->keys();
        $ring = $this->ring(['cache-a', 'cache-b', 'cache-c', 'cache-d']);
        $before = $this->owners($ring, $keys);

        $ring->removeNode('cache-b'); // it crashed
        $after = $this->owners($ring, $keys);

        foreach ($keys as $key) {
            if ($before[$key] === 'cache-b') {
                $this->assertNotSame('cache-b', $after[$key]);
            } else {
                $this->assertSame($before[$key], $after[$key], 'Keys on surviving nodes do not move.');
            }
        }
    }

    public function test_a_removed_nodes_load_is_shared_among_all_survivors(): void
    {
        $keys = $this->keys();
        $ring = $this->ring(['cache-a', 'cache-b', 'cache-c', 'cache-d']);
        $before = $this->owners($ring, $keys);

        $ring->removeNode('cache-b');

        $inheritedBy = [];

        foreach ($keys as $key) {
            if ($before[$key] === 'cache-b') {
                $inheritedBy[$ring->nodeFor($key)] = true;
            }
        }

        $this->assertCount(3, $inheritedBy, 'Thanks to virtual nodes, no single neighbour takes the whole hit.');
    }

    public function test_virtual_nodes_even_out_the_load(): void
    {
        $keys = $this->keys(10_000);
        $nodes = ['cache-a', 'cache-b', 'cache-c', 'cache-d'];

        $imbalance = function (int $virtualNodes) use ($keys, $nodes): float {
            $distribution = $this->ring($nodes, $virtualNodes)->distribution($keys);

            return max($distribution) / max(1, min($distribution));
        };

        $this->assertGreaterThan(1.5, $imbalance(1), 'One point per node: the arcs, and so the loads, are very unequal.');
        $this->assertLessThan(1.35, $imbalance(200), 'Two hundred points per node: close to an even split.');
    }

    public function test_the_preference_list_names_distinct_nodes_for_replication(): void
    {
        $ring = $this->ring(['db-a', 'db-b', 'db-c', 'db-d', 'db-e']);

        $replicas = $ring->nodesFor('order:1001', 3);

        $this->assertCount(3, $replicas);
        $this->assertCount(3, array_unique($replicas), 'Three different machines, never the same one twice.');
        $this->assertSame($ring->nodeFor('order:1001'), $replicas[0], 'The first entry is the key\'s owner.');
    }

    public function test_the_preference_list_cannot_be_longer_than_the_cluster(): void
    {
        $this->assertCount(2, $this->ring(['db-a', 'db-b'])->nodesFor('order:1001', 3));
    }
}
