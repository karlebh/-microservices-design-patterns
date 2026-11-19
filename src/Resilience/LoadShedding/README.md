# Load Shedding & Request Throttling

## The problem

Every service has a maximum throughput. Push it past that point and it doesn't just get slower — it **collapses**: queues grow, every request waits longer than the caller's timeout, callers retry, and the server spends all its capacity on requests whose answers nobody is waiting for any more. Useful work done (*goodput*) falls towards zero while the machine is at 100% CPU.

Serving 100% of requests badly is worse than serving 80% well and refusing 20% quickly.

## Two complementary tools

| | Load shedding | Request throttling |
| --- | --- | --- |
| Whose point of view | The **server** protecting itself | A **client** being held to its share |
| Triggered by | Overall load on the server | That one client's own rate |
| Who is refused | The least important work first | Whoever exceeds their quota |
| Response | `503 Service Unavailable` | `429 Too Many Requests` + `Retry-After` |
| Goal | Survive overload | Fairness between clients |

## In this folder

| File | Role |
| --- | --- |
| `Priority.php` | `Critical`, `Normal`, `Sheddable` |
| `LoadShedder.php` | Admits or rejects based on current utilization, dropping the lowest priority first |
| `AdaptiveConcurrencyLimit.php` | Finds the right concurrency limit by itself from observed latency (AIMD) |
| `Throttler.php` | Per-client token bucket that reports how long to wait |

## What to notice

- **Shed early and cheaply.** Reject at the front door, before parsing the body or touching the database. A rejection must cost almost nothing.
- **Shed by priority.** Background refreshes go first, browsing next, checkout and health checks last. All requests are not equal.
- **Don't guess the capacity.** A fixed "max 100 concurrent" is wrong the moment a dependency slows down. The adaptive limit behaves like TCP congestion control: grow slowly while latency is good, cut sharply when it isn't.
- **Throttling slows clients down; it doesn't punish them.** `Retry-After` tells a well-behaved client exactly when to come back.
- Clients must cooperate: [backoff with jitter](../RetryWithBackoff) on 429/503, or the shed load returns immediately as retries.

## Try it

```bash
php artisan test --filter=LoadSheddingTest
```
