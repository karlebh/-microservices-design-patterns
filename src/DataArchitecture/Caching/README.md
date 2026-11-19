# Cache Aside, Write-Through, Write-Back, and Refresh-Ahead Caching

A cache keeps a copy of slow-to-get data somewhere fast. The four strategies differ in **who** keeps the copy in sync with the database, and **when**.

| Strategy | Read | Write | Consistency | Risk |
| --- | --- | --- | --- | --- |
| **Cache-aside** (lazy loading) | App checks the cache; on a miss it loads from the DB and stores the result | App writes the DB, then **invalidates** the cache entry | Stale until invalidated / TTL | First read after every invalidation is slow |
| **Write-through** | From cache | App writes cache **and** DB synchronously | Cache always matches the DB | Every write pays for both; caches data nobody may read |
| **Write-back** (write-behind) | From cache | App writes the cache only; the DB is updated **later**, in batches | DB lags behind the cache | **Data loss** if the cache dies before flushing |
| **Refresh-ahead** | From cache; entries close to expiry are refreshed in the background | – | Hot keys never expire | Refreshes data that may not be requested again |

## In this folder

| File | Role |
| --- | --- |
| `Database.php` | The slow source of truth, counting reads and writes |
| `Cache.php` | A TTL cache driven by an injected clock, counting hits and misses |
| `CacheAside.php`, `WriteThrough.php`, `WriteBehind.php`, `RefreshAhead.php` | One class per strategy |

## What to notice

- **Cache-aside invalidates instead of updating.** If two writers race, "delete the entry" can't leave a stale value behind; "set the entry" can.
- **Write-behind coalesces.** A hundred updates to a counter become one database write — that is its whole appeal, and the unflushed buffer is its whole danger.
- **Refresh-ahead trades wasted work for latency.** No user ever waits for a reload of a popular key.
- They combine: cache-aside for reads plus write-through for writes is common.
- None of them answers what happens when a popular entry expires under heavy traffic — that is the [cache stampede](../CacheStampede).
- "There are only two hard things in Computer Science: cache invalidation and naming things." Always set a TTL as a safety net.

## Try it

```bash
php artisan test --filter=CachingTest
```
