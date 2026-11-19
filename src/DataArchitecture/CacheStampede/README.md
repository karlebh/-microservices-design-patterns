# Cache Stampede / Thundering Herd Mitigation

## The problem

A popular cache entry — the home page data, a price list — takes 200 ms to compute and is requested 500 times a second. While it is cached, the database is idle. The moment it **expires**, every request arriving in the next 200 ms finds nothing in the cache and starts the same expensive computation. A hundred identical queries hit the database at once, it slows down, the computation takes longer, more requests pile in...

The cache was hiding load the database cannot actually carry, and expiry exposes all of it at one instant.

## The mitigations

| Technique | Idea | Who waits? |
| --- | --- | --- |
| **Locking** (single flight) | The first request to miss takes a lock and recomputes; the others wait for its result | Everyone, once, for one computation |
| **Stale-while-revalidate** | Keep serving the expired value while one request refreshes it | Nobody |
| **Probabilistic early expiration** (XFetch) | Each request may decide to refresh *before* expiry, with a probability that rises as expiry approaches | Nobody — the entry never actually expires under load |
| **TTL jitter** | Add randomness to every TTL so that entries cached together don't expire together | – (prevents *many keys* stampeding at once) |

## In this folder

| File | Role |
| --- | --- |
| `StampedeCache.php` | A cache whose recomputations take time, with each protection selectable |
| `Protection.php` | `None`, `Lock`, `StaleWhileRevalidate`, `ProbabilisticEarlyExpiration` |
| `TtlJitter.php` | Randomises a TTL within a spread |

Concurrency is simulated: a recomputation started at time *t* only becomes visible at *t + computeMs*, so requests arriving in between behave as they would in parallel.

## What to notice

- The unprotected test does **100 recomputations** for 100 requests. Each protection brings that to **1**.
- **Lock** protects the database but stalls users. **Stale-while-revalidate** stalls nobody but serves slightly old data. Pick per use case.
- **XFetch** needs no lock and no coordination between servers: `refresh early if now − Δ·β·ln(rand) ≥ expiry`, where Δ is how long the value takes to compute. Slow-to-compute values get refreshed earlier.
- In Laravel: `Cache::lock()` around the recompute, or `Cache::flexible()` for stale-while-revalidate.
- Related: [Caching strategies](../Caching), and refresh-ahead in particular.

## Try it

```bash
php artisan test --filter=CacheStampedeTest
```
