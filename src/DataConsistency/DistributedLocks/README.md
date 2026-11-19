# Distributed Locks (Redlock, Chubby)

## The problem

Several processes on different machines must not do the same thing at the same time: send the newsletter twice, run the same cron on every server, write the same file concurrently. An in-process mutex is useless — the processes don't share memory. They need a lock held in something they all can reach.

## The idea

A lock is a record in a shared store saying "X is held by owner T until time E".

- **Acquire** = create the record only if it doesn't exist (atomic set-if-absent).
- **TTL** = the record expires by itself, so a crashed holder can't block everyone forever.
- **Owner token** = only the process that created the record may delete it.

## In this folder

| Folder | What it shows |
| --- | --- |
| `Newsletter/` | The Laravel way: `Cache::lock()` guarding a queued job, plus the scheduler's `withoutOverlapping()` (see `routes/console.php`). `POST /api/newsletter/send` |
| `Redlock/` | The Redlock algorithm: take the lock on a **majority** of N independent Redis nodes, so losing one node doesn't lose (or duplicate) the lock |
| `Chubby/` | A Chubby/ZooKeeper-style lock **service**: sessions kept alive by leases, and every acquisition returns a **sequencer** (a generation number) |

## What to notice

- **Hold the lock for the whole job, not just the dispatch.** `Cache::lock()->get(fn)` releases as soon as the closure returns. For queued work, acquire without a closure, pass the owner token to the job, and let the job release it (`Cache::restoreLock`).
- **Redlock validity:** time spent acquiring is subtracted from the TTL. If acquiring took too long, the lock is already (almost) expired and must be treated as not acquired.
- **Failed acquisition must clean up**, otherwise the minority of nodes you did lock block everyone until the TTL.
- **A TTL lock is not a safety guarantee.** A holder can pause (GC, slow disk, network) past its TTL and still believe it holds the lock. The protection is a monotonically increasing token checked by the resource itself — that is Chubby's *sequencer*; see [Fencing Tokens](../../Coordination/FencingTokens).
- Use a lock for **efficiency** (avoid duplicate work) freely; use it for **correctness** only together with fencing.

## Try it

```bash
php artisan test --filter=DistributedLocksTest
```
