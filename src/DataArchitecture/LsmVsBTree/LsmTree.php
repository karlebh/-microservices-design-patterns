<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\LsmVsBTree;

/**
 * A log-structured merge tree.
 *
 *   put / delete ──► MEMTABLE (in memory)
 *                        │ full? flush
 *                        ▼
 *                    SSTable 3   (newest)   immutable, sorted
 *                    SSTable 2
 *                    SSTable 1   (oldest)
 *                        │ compaction merges them into one
 *
 * Nothing on "disk" is ever modified. An update is a newer entry that
 * shadows the older one; a delete is a tombstone that shadows it.
 */
final class LsmTree
{
    /** @var array<string, ?string> key => value, or null for a tombstone */
    private array $memtable = [];

    /** @var list<array{entries: array<string, ?string>, bloom: BloomFilter}> newest first */
    private array $sstables = [];

    /** SSTables a get() actually had to look inside (read amplification). */
    public int $sstablesRead = 0;

    /** SSTables a get() could skip because the Bloom filter ruled them out. */
    public int $sstablesSkippedByBloomFilter = 0;

    /** Entries written by flushes and compactions (write amplification). */
    public int $entriesWrittenToDisk = 0;

    /** @param int $memtableLimit entries the memtable holds before it is flushed (megabytes, in a real engine) */
    public function __construct(private readonly int $memtableLimit = 4) {}

    /** A write only touches memory. Note what is absent: no lookup of the old value, no disk read. */
    public function put(string $key, string $value): void
    {
        $this->memtable[$key] = $value;

        $this->flushIfFull();
    }

    /** Deleting is writing: a tombstone that hides whatever older files still contain. */
    public function delete(string $key): void
    {
        $this->memtable[$key] = null;

        $this->flushIfFull();
    }

    public function get(string $key): ?string
    {
        // Newest data first: the memtable...
        if (array_key_exists($key, $this->memtable)) {
            return $this->memtable[$key];
        }

        // ...then each SSTable from newest to oldest. The first hit wins,
        // even if it is a tombstone (null).
        foreach ($this->sstables as $sstable) {
            if (! $sstable['bloom']->mightContain($key)) {
                $this->sstablesSkippedByBloomFilter++;

                continue;
            }

            $this->sstablesRead++;

            if (array_key_exists($key, $sstable['entries'])) {
                return $sstable['entries'][$key];
            }
        }

        return null;
    }

    /**
     * All live entries with $from <= key <= $to, sorted. Every SSTable may
     * hold part of the range, so they all have to be merged.
     *
     * @return array<string, string>
     */
    public function scan(string $from, string $to): array
    {
        $merged = [];

        // Oldest first, so newer entries overwrite older ones.
        foreach ([...array_reverse(array_column($this->sstables, 'entries')), $this->memtable] as $entries) {
            foreach ($entries as $key => $value) {
                $key = (string) $key;

                if (strcmp($key, $from) >= 0 && strcmp($key, $to) <= 0) {
                    $merged[$key] = $value;
                }
            }
        }

        $live = array_filter($merged, fn (?string $value) => $value !== null);
        ksort($live, SORT_STRING);

        return $live;
    }

    /** Write the memtable out as a new, immutable, sorted file. */
    public function flush(): void
    {
        if ($this->memtable === []) {
            return;
        }

        ksort($this->memtable, SORT_STRING);

        array_unshift($this->sstables, $this->sstable($this->memtable));
        $this->memtable = [];
    }

    /**
     * Merge all SSTables into one.
     *
     * For each key only the newest entry is kept. Tombstones can be dropped
     * too, because after a full merge there is no older value left for them
     * to hide. This is where the space of updates and deletes is reclaimed —
     * and where data gets rewritten again (write amplification).
     */
    public function compact(): void
    {
        $merged = [];

        foreach (array_reverse($this->sstables) as $sstable) {
            $merged = array_replace($merged, $sstable['entries']);
        }

        $merged = array_filter($merged, fn (?string $value) => $value !== null);
        ksort($merged, SORT_STRING);

        $this->sstables = $merged === [] ? [] : [$this->sstable($merged)];
    }

    public function sstableCount(): int
    {
        return count($this->sstables);
    }

    /** Entries currently held in SSTables, including shadowed ones and tombstones. */
    public function entriesOnDisk(): int
    {
        return array_sum(array_map(fn (array $sstable) => count($sstable['entries']), $this->sstables));
    }

    /**
     * @param  array<string, ?string>  $entries  already sorted
     * @return array{entries: array<string, ?string>, bloom: BloomFilter}
     */
    private function sstable(array $entries): array
    {
        $bloom = new BloomFilter;

        foreach (array_keys($entries) as $key) {
            $bloom->add((string) $key);
        }

        $this->entriesWrittenToDisk += count($entries);

        return ['entries' => $entries, 'bloom' => $bloom];
    }

    private function flushIfFull(): void
    {
        if (count($this->memtable) >= $this->memtableLimit) {
            $this->flush();
        }
    }
}
