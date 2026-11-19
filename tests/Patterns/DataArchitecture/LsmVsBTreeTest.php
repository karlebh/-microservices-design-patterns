<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\LsmVsBTree\BloomFilter;
use Patterns\DataArchitecture\LsmVsBTree\BTree;
use Patterns\DataArchitecture\LsmVsBTree\LsmTree;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class LsmVsBTreeTest extends TestCase
{
    /** @return list<string> $count distinct keys in a shuffled (random insertion) order */
    private function shuffledKeys(int $count): array
    {
        $keys = array_map(fn (int $i) => sprintf('key-%05d', $i), range(1, $count));

        return (new Randomizer(new Mt19937(1)))->shuffleArray($keys);
    }

    // ==== LSM tree =======================================================================

    public function test_lsm_reads_the_newest_version_of_a_key(): void
    {
        $lsm = new LsmTree(memtableLimit: 2);

        $lsm->put('user:1', 'Ada');
        $lsm->put('user:2', 'Bob');       // memtable full: flushed to SSTable #1
        $lsm->put('user:1', 'Ada Lovelace');
        $lsm->put('user:3', 'Cy');        // flushed to SSTable #2

        $this->assertSame(2, $lsm->sstableCount());
        $this->assertSame('Ada Lovelace', $lsm->get('user:1'), 'The old value still exists in an older file but is shadowed.');
        $this->assertSame('Bob', $lsm->get('user:2'));
        $this->assertNull($lsm->get('user:9'));
    }

    public function test_lsm_writes_never_read_from_disk(): void
    {
        $lsm = new LsmTree(memtableLimit: 50);

        foreach ($this->shuffledKeys(1_000) as $key) {
            $lsm->put($key, 'v');
        }

        $this->assertSame(0, $lsm->sstablesRead, 'A thousand writes, not one lookup: this is why LSM trees ingest so fast.');
        $this->assertSame(20, $lsm->sstableCount());
    }

    public function test_lsm_deletes_by_writing_a_tombstone(): void
    {
        $lsm = new LsmTree(memtableLimit: 2);

        $lsm->put('session:1', 'active');
        $lsm->put('session:2', 'active'); // flushed
        $lsm->delete('session:1');

        $this->assertNull($lsm->get('session:1'), 'Hidden by the tombstone in the memtable.');

        $lsm->flush();

        $this->assertNull($lsm->get('session:1'), 'Still hidden once the tombstone is on disk.');
        $this->assertSame(3, $lsm->entriesOnDisk(), 'A delete made the data on disk GROW: two values plus a tombstone are stored.');
    }

    public function test_lsm_compaction_merges_files_and_reclaims_space(): void
    {
        $lsm = new LsmTree(memtableLimit: 2);

        $lsm->put('a', '1');
        $lsm->put('b', '1');
        $lsm->put('a', '2');   // overwrites a
        $lsm->put('c', '1');
        $lsm->delete('b');
        $lsm->put('d', '1');

        $this->assertSame(3, $lsm->sstableCount());
        $this->assertSame(6, $lsm->entriesOnDisk());

        $lsm->compact();

        $this->assertSame(1, $lsm->sstableCount());
        $this->assertSame(3, $lsm->entriesOnDisk(), 'Only a, c and d remain: the stale "a", the deleted "b" and its tombstone are gone.');
        $this->assertSame(['a' => '2', 'c' => '1', 'd' => '1'], $lsm->scan('a', 'z'));
    }

    public function test_lsm_reads_get_cheaper_after_compaction(): void
    {
        $lsm = new LsmTree(memtableLimit: 10);
        $keys = $this->shuffledKeys(200);

        foreach ($keys as $key) {
            $lsm->put($key, 'v');
        }

        // With 20 files and no Bloom filter help, a miss would open all of them.
        // Count the files opened to read every key once.
        $lsm->sstablesRead = 0;
        array_map($lsm->get(...), $keys);
        $before = $lsm->sstablesRead;

        $lsm->compact();

        $lsm->sstablesRead = 0;
        array_map($lsm->get(...), $keys);
        $after = $lsm->sstablesRead;

        $this->assertSame(200, $after, 'One file to check per lookup.');
        $this->assertGreaterThanOrEqual($after, $before);
    }

    public function test_lsm_bloom_filters_avoid_reading_files_that_cannot_contain_the_key(): void
    {
        $lsm = new LsmTree(memtableLimit: 10);

        foreach ($this->shuffledKeys(200) as $key) {
            $lsm->put($key, 'v');
        }

        // Look up keys that do not exist anywhere.
        foreach (range(1, 100) as $i) {
            $this->assertNull($lsm->get("missing-{$i}"));
        }

        $this->assertGreaterThan(1_900, $lsm->sstablesSkippedByBloomFilter, 'Out of 2,000 possible file reads, almost all were avoided.');
        $this->assertLessThan(100, $lsm->sstablesRead, 'The few that happened are false positives.');
    }

    public function test_lsm_range_scans_merge_all_sorted_runs(): void
    {
        $lsm = new LsmTree(memtableLimit: 3);

        foreach (['d', 'b', 'f', 'a', 'e', 'c', 'g'] as $key) {
            $lsm->put($key, strtoupper($key));
        }

        $lsm->delete('c');

        $this->assertSame(['b' => 'B', 'd' => 'D', 'e' => 'E'], $lsm->scan('b', 'e'));
    }

    public function test_lsm_rewrites_data_more_than_once(): void
    {
        $lsm = new LsmTree(memtableLimit: 10);

        foreach ($this->shuffledKeys(100) as $key) {
            $lsm->put($key, 'v');
        }

        $lsm->compact();

        $this->assertSame(200, $lsm->entriesWrittenToDisk, '100 entries were written twice: once by a flush, once by compaction (write amplification).');
    }

    // ==== Bloom filter ===================================================================

    public function test_a_bloom_filter_has_no_false_negatives_and_few_false_positives(): void
    {
        $filter = new BloomFilter(sizeBits: 2_048, hashCount: 4);

        foreach (range(1, 100) as $i) {
            $filter->add("member-{$i}");
        }

        foreach (range(1, 100) as $i) {
            $this->assertTrue($filter->mightContain("member-{$i}"), 'Something that was added is never reported absent.');
        }

        $falsePositives = count(array_filter(range(1, 1_000), fn (int $i) => $filter->mightContain("stranger-{$i}")));

        $this->assertLessThan(30, $falsePositives, 'Under 3% of non-members are mistaken for members.');
    }

    // ==== B-tree =========================================================================

    public function test_btree_finds_every_key_whatever_the_insertion_order(): void
    {
        $tree = new BTree(maxKeys: 4);
        $keys = $this->shuffledKeys(500);

        foreach ($keys as $key) {
            $tree->put($key, "value of {$key}");
        }

        foreach ($keys as $key) {
            $this->assertSame("value of {$key}", $tree->get($key));
        }

        $this->assertNull($tree->get('key-99999'));
    }

    public function test_btree_updates_a_value_in_place(): void
    {
        $tree = new BTree;
        $tree->put('user:1', 'Ada');
        $tree->put('user:1', 'Ada Lovelace');

        $this->assertSame('Ada Lovelace', $tree->get('user:1'));
        $this->assertSame(['user:1' => 'Ada Lovelace'], $tree->range('a', 'z'), 'One copy only: nothing to compact later.');
    }

    public function test_btree_stays_shallow_by_splitting_nodes(): void
    {
        $tree = new BTree(maxKeys: 4);
        $this->assertSame(1, $tree->height());

        foreach ($this->shuffledKeys(1_000) as $key) {
            $tree->put($key, 'v');
        }

        $this->assertGreaterThan(0, $tree->splits);
        $this->assertLessThanOrEqual(7, $tree->height(), '1,000 keys with tiny 4-key nodes still need only a handful of levels.');
    }

    public function test_btree_lookup_cost_is_exactly_the_height_of_the_tree(): void
    {
        $tree = new BTree(maxKeys: 4);

        foreach ($this->shuffledKeys(1_000) as $key) {
            $tree->put($key, 'v');
        }

        $tree->nodesRead = 0;
        $tree->get('key-00500');

        $this->assertSame($tree->height(), $tree->nodesRead, 'Predictable: the same number of page reads for any key, present or not.');
    }

    public function test_btree_wider_nodes_make_a_flatter_tree(): void
    {
        $narrow = new BTree(maxKeys: 4);
        $wide = new BTree(maxKeys: 64);

        foreach ($this->shuffledKeys(2_000) as $key) {
            $narrow->put($key, 'v');
            $wide->put($key, 'v');
        }

        $this->assertLessThanOrEqual(3, $wide->height(), 'Real pages hold hundreds of keys, so billions of rows fit in 3-4 levels.');
        $this->assertGreaterThan($wide->height(), $narrow->height());
    }

    public function test_btree_range_scan_returns_sorted_keys_without_visiting_the_whole_tree(): void
    {
        $tree = new BTree(maxKeys: 4);

        foreach ($this->shuffledKeys(1_000) as $key) {
            $tree->put($key, 'v');
        }

        $tree->nodesRead = 0;
        $range = $tree->range('key-00100', 'key-00109');

        $this->assertSame(
            array_map(fn (int $i) => sprintf('key-%05d', $i), range(100, 109)),
            array_keys($range),
        );
        $this->assertLessThan(30, $tree->nodesRead, 'Ten rows out of a thousand, and only the pages on the way to them were read.');
    }

    // ==== Side by side ===================================================================

    public function test_a_btree_write_has_to_read_first_while_an_lsm_write_does_not(): void
    {
        $tree = new BTree(maxKeys: 4);
        $lsm = new LsmTree(memtableLimit: 50);

        foreach ($this->shuffledKeys(1_000) as $key) {
            $tree->put($key, 'v');
            $lsm->put($key, 'v');
        }

        $this->assertGreaterThan(3_000, $tree->nodesRead, 'Every insert walked from the root to a leaf to find its page.');
        $this->assertSame(0, $lsm->sstablesRead, 'The LSM tree only appended.');
    }

    public function test_both_structures_agree_on_the_data(): void
    {
        $tree = new BTree(maxKeys: 4);
        $lsm = new LsmTree(memtableLimit: 7);
        $random = new Randomizer(new Mt19937(3));

        // A workload with many overwrites of the same keys.
        foreach (range(1, 2_000) as $i) {
            $key = sprintf('key-%03d', $random->getInt(1, 150));

            $tree->put($key, "v{$i}");
            $lsm->put($key, "v{$i}");
        }

        $this->assertSame($tree->range('key-000', 'key-999'), $lsm->scan('key-000', 'key-999'));

        $lsm->compact();
        $this->assertSame($tree->range('key-000', 'key-999'), $lsm->scan('key-000', 'key-999'), 'Compaction changes the files, never the answers.');
    }
}
