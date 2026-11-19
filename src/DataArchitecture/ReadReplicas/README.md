# Read Replicas & CQRS-driven Data Synchronization

## The problem

Most applications read far more than they write. One database server handling everything becomes the bottleneck, and scaling it *up* gets expensive fast.

## The idea

Keep one **primary** that accepts all writes and ship its change log to one or more **replicas** that serve reads. Reads scale *out* by adding replicas.

```
writes ──► PRIMARY ──(replication log)──► REPLICA 1 ◄── reads
                   └────────────────────► REPLICA 2 ◄── reads
```

Replication is usually **asynchronous**, so a replica is always a little behind (*replication lag*). That is the price: reads can be **stale**.

The same log can feed something that isn't a copy at all: a **projection** shaped for a specific query (counts, search documents, a dashboard table). That is CQRS-driven synchronization — the read side is derived from the write side's change stream.

## In this folder

| File | Role |
| --- | --- |
| `Http/TaskController.php`, `Models/Task.php` | A normal Laravel CRUD API (`/api/tasks`). The read/write split is pure configuration: see `read`, `write` and `sticky` under `pgsql`/`mysql` in `config/database.php` |
| `Simulation/Primary.php` | Accepts writes and appends each one to a replication log with an increasing LSN |
| `Simulation/Replica.php` | Applies the log later; exposes its lag |
| `Simulation/ReplicaRouter.php` | Writes → primary, reads → replicas, with three consistency modes |
| `Simulation/TaskStatsProjection.php` | A read model built from the same log (CQRS-driven sync) |

## What to notice

- **Stale reads are normal.** "Save, redirect, and the new row isn't there" is replication lag, not a bug in your code.
- **Read-your-writes** fixes the most visible symptom. Two ways:
  - *Sticky primary* — after a write, send this request's reads to the primary. This is exactly Laravel's `'sticky' => true`.
  - *LSN token* — remember the log position of your last write and only read from a replica that has reached it. Keeps load off the primary.
- Replicas scale **reads only**. Every replica still applies every write; for write scaling see [Sharding](../Sharding).
- Lag must be monitored: a replica that falls far behind should be taken out of rotation.

## Try it

```bash
php artisan test --filter=ReadReplicasTest
```
