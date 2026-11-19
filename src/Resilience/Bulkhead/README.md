# Bulkhead Isolation Pattern

## The problem

Your service calls several dependencies using one shared pool of resources (threads, workers, connections). One dependency — say, recommendations — becomes slow. Every request that touches it now holds a worker for 30 seconds instead of 30 milliseconds. Soon **all** workers are stuck waiting on recommendations, and there are none left to serve checkout, which has nothing to do with it.

A slow, unimportant dependency has taken down the whole service.

## The idea

A ship's hull is divided into watertight compartments (bulkheads): a hole floods one compartment, not the ship. Do the same with resources: give each dependency its **own, limited** share.

```
shared pool (10)                      bulkheads
┌──────────────────────┐              ┌── recommendations: 3 ──┐  ┌── payments: 5 ──┐
│ R R R R R R R R R R  │  ← all       │ R R R                  │  │ P P . . .       │
│ payments: no room    │    stuck     │ 4th call: rejected     │  │ still has room  │
└──────────────────────┘              └────────────────────────┘  └─────────────────┘
```

When a compartment is full, further calls to *that* dependency are **rejected immediately** instead of queueing up, and everything else carries on.

## In this folder

| File | Role |
| --- | --- |
| `Bulkhead.php` | A counting semaphore with an optional bounded waiting queue |
| `BulkheadFullException.php` | Thrown at once when the compartment has no room |

## What to notice

- **Rejecting is the feature.** A fast "no" keeps the caller's own resources free; a slow "maybe" spreads the outage.
- **Size each compartment** from the dependency's normal latency and call rate, with some headroom. Too small rejects healthy traffic; too large isolates nothing.
- **Unbounded queues defeat the purpose.** Work waits in them, latency grows and memory fills. The queue here is bounded.
- Works best with a **timeout** (so permits come back), a [circuit breaker](../CircuitBreaker) (so you stop trying at all) and a [fallback](../GracefulDegradation) (so "rejected" still gives the user something).
- **In PHP**, workers are processes, so the shared pool is the PHP-FPM pool and the semaphore has to live in shared storage. Laravel's `Redis::funnel('recommendations')->limit(3)` is exactly this pattern. Separate FPM pools or separate queues per workload are bulkheads too.

## Try it

```bash
php artisan test --filter=BulkheadTest
```
