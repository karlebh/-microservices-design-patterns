# Database Sharding & Horizontal Partitioning

## The problem

[Read replicas](../ReadReplicas) scale reads, but every replica still stores **all** the data and applies **every** write. When the dataset no longer fits on one machine, or the write rate exceeds what one primary can take, replicas don't help.

## The idea

Split the rows across several independent databases (**shards**). Each row lives on exactly one shard, chosen by its **shard key**. Each shard holds a fraction of the data and takes a fraction of the writes.

```
                 ┌─► shard-0   users a-h
app ─► ROUTER ───┼─► shard-1   users i-q        (shard key: username)
                 └─► shard-2   users r-z
```

(*Vertical* partitioning splits a table by **columns**; *horizontal* partitioning — sharding — splits it by **rows**.)

## How to map a key to a shard

| Strategy | Mapping | Strength | Weakness |
| --- | --- | --- | --- |
| **Hash** | `hash(key) mod N` | Even spread, no hot shard from sequential keys | Range scans hit every shard; changing N moves almost every row |
| **Range** | Key falls in a shard's range (`a-h`) | Range scans stay on one shard | Skew: popular ranges and "latest" keys overload one shard |
| **Directory** | A lookup table says where each key lives | Any key can be moved individually (e.g. a big tenant to its own shard) | The directory is one more critical, must-be-fast dependency |

## In this folder

| File | Role |
| --- | --- |
| `ShardingStrategy.php` | `shardFor(key)` |
| `HashSharding.php`, `RangeSharding.php`, `DirectorySharding.php` | The three strategies |
| `ShardedDatabase.php` | Routes single-key operations to one shard, runs scatter-gather queries across all, and rebalances |

## What to notice

- **The shard key decides everything.** A query that includes it goes to one shard. A query that doesn't must ask **every** shard and merge (scatter-gather): slow, and as available as the least available shard.
- **Joins and transactions across shards are gone** (or become [sagas](../../DataConsistency/Saga) / [2PC](../../DataConsistency/CommitProtocols)). Choose a key that keeps related rows together, e.g. `tenant_id`.
- **Resharding with `mod N` is brutal**: the test shows most rows change shard when a fourth shard is added. That is the motivation for [consistent hashing](../ConsistentHashing).
- **Hot spots**: a celebrity account or an auto-incrementing key under range sharding puts the load on one shard.
- Sharding is a last resort. Exhaust vertical scaling, replicas, caching and archiving first.

## Try it

```bash
php artisan test --filter=ShardingTest
```
