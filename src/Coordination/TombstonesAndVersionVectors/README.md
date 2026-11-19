# Tombstones & Version Vectors

Two pieces of metadata that leaderless, eventually consistent stores (Dynamo, Cassandra, Riak) need in order to merge replicas correctly.

## Tombstones — how to delete something

Replicas A and B both hold `user:1`. A client deletes it on A. If "delete" just removes the row, A now has *nothing* — and "nothing" looks exactly like "never heard of it". At the next sync, B says "I have `user:1`, you don't, here you go", and the deleted data **comes back to life**.

A delete has to be a piece of information that can be replicated. So instead of removing the value, write a **tombstone**: a marker that says "this key was deleted, as of this version". The tombstone beats the older value on every replica it reaches.

Tombstones can't be kept forever (they are still data), so they are purged after a grace period (`gc_grace_seconds` in Cassandra). The hazard: a replica that was **offline for longer than the grace period** still has the old value, the tombstone that would have killed it is gone, and the data is resurrected after all. Hence the operational rule: repair every node more often than the grace period.

## Version vectors — which copy is newer?

When two replicas hold different values for a key, the merge needs to know whether one **supersedes** the other or whether they were written **independently**. Timestamps can't tell (clocks disagree) and just picking one loses data.

A version vector records, per replica, how many updates from that replica a value includes: `{A: 2, B: 1}`.

| Comparison | Meaning | Merge |
| --- | --- | --- |
| X ≥ Y in every entry | X has seen everything Y has: X **descends from** Y | Keep X, drop Y |
| neither ≥ the other | written **concurrently** | Keep **both** as *siblings*; the application (or the next writer) resolves |

A write that follows a read of all siblings gets a vector that dominates them all, which resolves the conflict.

(A *vector clock* orders events; a *version vector* orders replica states. The mechanics are the same — see [Logical Clocks](../LogicalClocks).)

## In this folder

| File | Role |
| --- | --- |
| `VersionVector.php` | Per-replica update counters with `dominates` / `merge` |
| `Replica.php` | One replica: put, delete (with or without tombstones), anti-entropy sync that keeps siblings, tombstone garbage collection |

## Try it

```bash
php artisan test --filter=TombstonesAndVersionVectorsTest
```
