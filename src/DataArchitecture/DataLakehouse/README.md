# Data Lakehouse (Iceberg, Hudi, Delta Lake)

## The problem

A **data lake** is cheap, scalable object storage (S3) full of Parquet files. It has no transactions: a job that dies half way leaves partial files that readers pick up, two writers silently overwrite each other, and "update one row" means rewriting directories by hand.

A **data warehouse** has transactions, schemas and fast queries, but it is expensive and locks the data inside one engine.

## The idea

Keep the cheap files, and add a **table format**: a layer of metadata that says *exactly which files make up the table right now*. That one addition gives a pile of files the behaviour of a database table — a **lakehouse**.

```
table/
  data/part-0001.parquet   ← immutable data files
  data/part-0002.parquet
  _log/00000001.json       ← commit 1: "add part-0001"
  _log/00000002.json       ← commit 2: "add part-0002, remove part-0001"
```

- A **commit** is one small metadata file listing the data files added and removed. The table's state at version N is the result of replaying commits 1…N.
- **Atomicity** comes from one primitive of the object store: *create this file only if it doesn't exist*. Whoever creates `_log/00000003.json` first wins; the other writer sees the conflict and retries (optimistic concurrency).
- Data files are **never modified**. Old versions stay readable until explicitly cleaned up.

## What you get

| Feature | How |
| --- | --- |
| ACID commits | A change is visible only when its commit file exists; readers never see partial writes |
| Snapshot isolation | A reader pins a version and ignores later commits |
| **Time travel** | Replay the log only up to an older version |
| **Schema evolution** | Adding a column is a metadata commit; old files are not rewritten |
| **Data skipping** | Each file's min/max per column is stored in the log, so files that can't match a filter are never opened |
| Deletes / updates | **Copy-on-write**: rewrite the affected file. **Merge-on-read**: write a small "these rows are deleted" file and merge at read time |

Copy-on-write makes writes expensive and reads cheap; merge-on-read is the reverse, until **compaction** folds the deletes in.

## The three formats

All implement the idea above; they differ in metadata layout and emphasis. **Delta Lake**: an ordered JSON transaction log (closest to this folder). **Apache Iceberg**: a tree of snapshot → manifest list → manifests, with hidden partitioning. **Apache Hudi**: built around upserts, with first-class copy-on-write and merge-on-read table types.

## In this folder

| File | Role |
| --- | --- |
| `ObjectStore.php` | In-memory S3: immutable objects and the atomic put-if-absent |
| `LakehouseTable.php` | The table format: transaction log, snapshots, time travel, skipping, schema evolution, CoW/MoR deletes, compaction, vacuum |
| `ConcurrentCommitException.php` | Another writer committed first |

## Try it

```bash
php artisan test --filter=DataLakehouseTest
```
