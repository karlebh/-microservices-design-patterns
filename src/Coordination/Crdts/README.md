# CRDTs (Conflict-free Replicated Data Types)

## The problem

Several replicas accept writes independently — two data centres in an [active-active](../../Resilience/Failover) setup, a phone that edits offline, collaborators in the same document. When they sync, their states have diverged. Something must reconcile them:

- **Last-write-wins** silently throws updates away.
- **Asking the user** ("which version do you want?") doesn't scale.
- **Consensus** before every write removes the problem, and with it the availability and the offline capability.

## The idea

Design the data type so that **concurrent updates can always be merged automatically**, and every replica that has seen the same updates ends up in the **same state** — whatever the order, however many times.

For state-based CRDTs this comes down to a `merge` function that is:

| Property | Meaning | Why it matters |
| --- | --- | --- |
| **Commutative** | `merge(a, b) = merge(b, a)` | Sync order doesn't matter |
| **Associative** | `merge(merge(a, b), c) = merge(a, merge(b, c))` | Grouping doesn't matter |
| **Idempotent** | `merge(a, a) = a` | Receiving the same state twice is harmless |

Then replicas can gossip states to each other in any pattern, over an unreliable network, and are guaranteed to converge (*strong eventual consistency*). No coordination, no conflicts.

## In this folder

| Type | Idea | Merge |
| --- | --- | --- |
| `GCounter` | Grow-only counter: one slot per replica | Max of each slot |
| `PNCounter` | Counter that can also decrease: two G-counters (increments, decrements) | Merge both |
| `GSet` | Grow-only set | Union |
| `TwoPhaseSet` | Set with removal: an "added" set and a "removed" set. Remove wins, forever | Union both |
| `ORSet` | Observed-remove set: every add gets a unique tag; a remove deletes only the tags it has seen. A concurrent add survives (add wins) and elements can be re-added | Union of tags, minus removed tags |
| `LWWRegister` | A single value with a timestamp | Highest timestamp wins |

## What to notice

- A naive counter (`value = 5`) can't be merged: `max` loses increments, `sum` double counts. Per-replica slots are what make the G-counter work.
- `TwoPhaseSet` vs `ORSet` shows that "what should a concurrent add and remove do?" is a **design decision** encoded in the type.
- `LWWRegister` converges, but by discarding one of two concurrent writes. A CRDT guarantees agreement, not that the agreed value is what users wanted.
- The cost is metadata: slots per replica, tags, tombstones (see [Tombstones & Version Vectors](../TombstonesAndVersionVectors)).

Real uses: Redis Enterprise, Riak, Automerge and Yjs (collaborative editing), shopping carts in Dynamo.

## Try it

```bash
php artisan test --filter=CrdtsTest
```
