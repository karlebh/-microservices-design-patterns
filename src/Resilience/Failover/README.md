# Failover & Active-Active / Active-Passive Clustering

## The problem

A single server is a single point of failure. Redundancy means running more than one copy — and then deciding **who serves traffic** and **what happens when one copy dies**.

Two numbers describe how good the answer is:

- **RTO** (recovery time objective) — how long until service is back.
- **RPO** (recovery point objective) — how much recent data may be lost.

## Active-passive

One node (the **primary**) does all the work. A **standby** receives a copy of the data and waits. When the primary stops answering heartbeats, the standby is **promoted**.

- ✔ Simple: one writer, so no conflicting writes.
- ✘ Failover takes time (detect + promote) → RTO > 0. The standby's capacity sits idle.
- ✘ With **asynchronous** replication, writes the primary accepted but had not yet shipped are **lost** → RPO > 0. **Synchronous** replication fixes that, at the cost of slower writes.
- ⚠ **Split brain**: the old primary may only have been cut off, not dead. If it comes back still thinking it is primary, two nodes accept writes. The cure is an **epoch number** that increases on every promotion; writes carrying an old epoch are refused.

## Active-active

Every node serves reads **and writes** all the time, and they replicate to each other.

- ✔ No promotion step: when a node dies, traffic simply goes to the others → RTO ≈ 0. All capacity is in use, and users are served by the nearest region.
- ✘ Two nodes can change the same record at the same time → **conflicts**. Something must resolve them; the common *last-write-wins* rule silently discards one of the updates.

## In this folder

| File | Role |
| --- | --- |
| `ActivePassiveCluster.php` | Heartbeat-based failure detection, promotion, epochs (fencing), sync vs async replication |
| `ActiveActiveCluster.php` | Multi-writer replication with last-write-wins conflict resolution and routing around a dead region |

## What to notice

- The failure detector can't tell "dead" from "slow" or "unreachable". A short timeout fails over on a hiccup; a long one extends the outage.
- Compare the two `lost write` tests: active-passive loses data **at failover**; active-active loses data **at conflict**. Neither is free.
- Avoiding conflicts beats resolving them: route each user (or each key) consistently to one region, or use data types that merge without loss ([CRDTs](../../Coordination/Crdts)).
- Related: [Leader Election](../../Coordination/LeaderElection), [Fencing Tokens](../../Coordination/FencingTokens), [Heartbeats & Leases](../../Coordination/HeartbeatsAndLeases).

## Try it

```bash
php artisan test --filter=FailoverTest
```
