# Fencing Tokens & Epoch Numbers

## The problem

A [distributed lock](../../DataConsistency/DistributedLocks) or a [lease](../HeartbeatsAndLeases) expires after a timeout so that a crashed holder can't block everyone forever. But that means the lock service can decide a holder is gone **while the holder is still running**:

```
client 1: acquire lock ─────── long GC pause / network stall ─────────── write!  ← believes it still holds the lock
lock:                  expires ──► granted to client 2
client 2:                              acquire ── write ── done
```

Client 1 did nothing wrong by its own logic: it checked the lock before pausing. No amount of care on the client side fixes this, because a process cannot know that it was paused. Two clients write "under the lock", and the data is corrupted.

The same thing happens to a deposed **leader** that hasn't noticed a newer one was elected (*split brain*).

## The idea

Stop trusting the client's belief. Make the **resource** check.

- Every time the lock is granted, the lock service hands out a **fencing token**: a number that only ever increases.
- The client must send its token with every write.
- The storage remembers the **highest token it has seen** and **rejects any write carrying a lower one**.

```
client 1: token 33 ─────────── pause ─────────────── write(33) ✗ rejected: 33 < 34
client 2:            token 34 ── write(34) ✓
```

A stale holder is now *fenced off* by the resource itself, however confused the client is.

**Epoch numbers** (also called terms or generations) are the same mechanism for leadership: every election increments the epoch, every message carries it, and followers ignore messages from an older epoch. Raft terms, ZooKeeper's `zxid` epoch and Kafka's leader epoch are all this.

## In this folder

| File | Role |
| --- | --- |
| `LockService.php` | A TTL lock that issues a strictly increasing token with each grant |
| `FencedStorage.php` | A resource that enforces tokens (and can be switched to *not* enforce them, to show the corruption) |
| `StaleTokenException.php` | A write arrived with an out-of-date token |
| `Follower.php` | Epoch numbers: a follower that only obeys the newest leader it has heard of |

## What to notice

- The check must be done **by the resource, at write time**. Checking "do I still hold the lock?" on the client just before writing only narrows the window.
- The token has to come from a system that guarantees monotonicity (ZooKeeper's `zxid`, etcd's revision, a database sequence). A random lock id (as in Redlock) cannot be used for fencing.
- It only works if the resource can participate. If it can't check tokens, a lock gives you efficiency, not safety.

## Try it

```bash
php artisan test --filter=FencingTokensTest
```
