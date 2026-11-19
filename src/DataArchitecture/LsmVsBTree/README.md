# Log-Structured Merge-trees (LSM Trees) vs B-Trees

Almost every database stores its data in one of these two structures. They make opposite bets.

## B-tree — update in place

Data lives in fixed-size **pages** arranged as a balanced tree. Each page holds sorted keys and pointers to child pages; the leaves hold the values.

- **Read:** walk from the root to a leaf: `height` page reads (3–4 for billions of rows). Predictable.
- **Write:** walk to the right leaf, then **modify that page in place**. If it is full, split it in two and push a separator key into the parent (which may split too).

Pages are scattered across the disk, so writes are **random I/O**, and one small change rewrites a whole page.

*PostgreSQL, MySQL/InnoDB, SQLite, most relational databases.*

## LSM tree — never update, only append

- **Write:** put the key into an in-memory sorted table (**memtable**). That's it. No disk read, no seek.
- When the memtable is full, **flush** it to disk as an immutable sorted file (**SSTable**). Sequential I/O.
- **Read:** check the memtable, then the SSTables from newest to oldest until the key is found. A **Bloom filter** per SSTable says "definitely not here" so most files are skipped.
- **Delete:** write a **tombstone**; the old value is still in an older file.
- **Compaction** merges SSTables in the background: newest value wins, overwritten values and tombstones are dropped.

*RocksDB, LevelDB, Cassandra, HBase, ScyllaDB, and the storage layer of many "NewSQL" systems.*

## The trade-off

| | B-tree | LSM tree |
| --- | --- | --- |
| Write path | read to find the page, then random write | append only, sequential |
| Write throughput | lower | **higher** |
| Point read | **`height` reads, always** | may check several files (helped by Bloom filters) |
| Range scan | **follow sorted leaves** | merge across all files |
| Space | pages are partly empty (fragmentation) | **compact**, but temporarily holds obsolete data |
| Background work | little | **compaction** competes with foreground traffic |
| Deletes | immediate | tombstone now, space reclaimed later |

Rule of thumb: **write-heavy → LSM, read-heavy → B-tree.**

## In this folder

| File | Role |
| --- | --- |
| `LsmTree.php` | Memtable, SSTables, tombstones, compaction, with counters for the work done |
| `BloomFilter.php` | "Possibly present / definitely absent" set membership |
| `BTree.php`, `BTreeNode.php` | A B+ tree with node splitting, point lookups and range scans |

## Try it

```bash
php artisan test --filter=LsmVsBTreeTest
```
