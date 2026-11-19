<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Caching;

/**
 * Cache-aside (lazy loading): the APPLICATION talks to both the cache and
 * the database, and the cache is filled only when something is asked for.
 *
 *   read:   cache hit? return it. Otherwise load from the DB, store, return.
 *   write:  update the DB, then DELETE the cache entry.
 *
 * This is what `Cache::remember()` does in Laravel.
 */
final class CacheAside
{
    public function __construct(
        private readonly Database $database,
        private readonly Cache $cache,
        private readonly int $ttlMs = 60_000,
    ) {}

    public function get(string $key): mixed
    {
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $this->database->read($key);

        if ($value !== null) {
            $this->cache->put($key, $value, $this->ttlMs);
        }

        return $value;
    }

    public function update(string $key, mixed $value): void
    {
        $this->database->write($key, $value);

        // Invalidate rather than overwrite. With two concurrent writers,
        // "set" can leave the older value in the cache; "delete" cannot.
        // The next reader reloads the truth from the database.
        $this->cache->forget($key);
    }
}
