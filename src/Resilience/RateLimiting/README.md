# Rate Limiting (Token Bucket, Leaky Bucket, Sliding Window Log, Fixed Window)

## The problem

Left unlimited, one misbehaving client (a bug in a loop, a scraper, an attacker) can consume all capacity and degrade the service for everyone. A rate limiter enforces "at most N requests per period, per client" and answers the rest with `429 Too Many Requests`.

The algorithms differ in **how they treat bursts**, **how accurate they are** and **how much they must remember**.

## The algorithms

| Algorithm | How it works | Bursts | Memory per client | Weak spot |
| --- | --- | --- | --- | --- |
| **Fixed window** | Count requests in the current clock window (e.g. this minute); reset when it rolls over | Up to 2× the limit across a window boundary | 1 counter | The boundary burst |
| **Sliding window log** | Keep a timestamp per request; count those inside the last period | None beyond the limit: exact | 1 timestamp per request | Memory at high limits |
| **Sliding window counter** | Current window's count + a weighted share of the previous window's | Approximately none | 2 counters | An approximation |
| **Token bucket** | Tokens drip in at a steady rate up to a capacity; each request spends one | **Allowed**, up to the bucket size | 2 numbers | Bursts reach the backend |
| **Leaky bucket** | Requests fill a bucket that drains at a constant rate; overflow is rejected | **Smoothed out** | 2 numbers | Bursts are rejected even after long idle |

*Token bucket* and *leaky bucket* are mirror images: token bucket **permits** a burst after a quiet period (good for user-facing APIs); leaky bucket **enforces a steady rate** no matter what (good for protecting a fragile downstream).

## In this folder

| File | Role |
| --- | --- |
| `RateLimiter.php` | The common interface: `allow(string $key): bool` |
| `FixedWindowLimiter.php`, `SlidingWindowLogLimiter.php`, `SlidingWindowCounterLimiter.php`, `TokenBucketLimiter.php`, `LeakyBucketLimiter.php` | One file per algorithm, each state kept per key |

## What to notice

- The test `fixed window allows a double burst at the boundary` is the classic argument against fixed windows; the sliding variants pass the same scenario.
- Compare what happens **after a quiet period**: token bucket lets a burst through, leaky bucket still drips.
- State here is in memory, per process. With several PHP workers or servers it must be shared **and updated atomically** — typically Redis with a Lua script, which is what Laravel's `RateLimiter` / `throttle` middleware do.
- Tell clients what happened: `429` plus `Retry-After` and `X-RateLimit-Remaining` headers.

## Try it

```bash
php artisan test --filter=RateLimitingTest
```
