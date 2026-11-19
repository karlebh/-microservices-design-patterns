# Write-Ahead Logging (WAL)

## The problem

A database keeps its working data in memory and in complex on-disk structures (B-trees, pages) that are updated in place. A crash in the middle of an update leaves those structures half-written. And data that was only in memory is simply gone, even though the client was told "saved".

Making every change safe by rewriting and syncing the main data files on each write would be far too slow: they are large and the writes land all over the disk.

## The idea

Before changing anything, **append a description of the change to a log file and flush it to disk**. Only then touch the real data structures.

```
set(k, v):   1. append "set k=v" to the log, fsync   ← now it is durable
             2. update the in-memory / on-disk data  ← this can be lazy
             3. reply "ok"
```

Appending to the end of one file is the cheapest disk write there is (sequential, small). After a crash, the database **replays the log** from the last checkpoint and redoes every change, arriving at exactly the state it had promised its clients.

A **checkpoint** writes the full current state to disk and discards the log up to that point, so the log doesn't grow forever and recovery stays short.

## In this folder

| File | Role |
| --- | --- |
| `WriteAheadLog.php` | A real append-only log **file**: length + CRC32 framing, fsync, replay, torn-write detection |
| `DurableKeyValueStore.php` | A key-value store that survives crashes by logging first, with checkpoints and recovery |

This one is not a simulation: it writes real files, and the tests corrupt them on purpose.

## What to notice

- **The rule is the name:** the log record is on disk *before* the change is applied. A test crashes the store between those two steps and shows the change is still there after restart.
- **Torn writes:** a crash can cut the last record short. Each record carries its length and a checksum, so recovery recognises the incomplete tail, ignores it, and trims it off.
- **Corruption is detected, not served.** A flipped bit fails the CRC; replay stops rather than apply garbage.
- **LSN** (log sequence number): every record has a strictly increasing number. The snapshot remembers the LSN it covers, so recovery replays only what came after — and replaying is idempotent.
- `fsync` is the price of durability. Real databases batch several transactions into one flush (*group commit*).
- The same log is what replicas follow ([Read Replicas](../ReadReplicas)) and what [CDC](../ChangeDataCapture) tails.

## Try it

```bash
php artisan test --filter=WriteAheadLogTest
```
