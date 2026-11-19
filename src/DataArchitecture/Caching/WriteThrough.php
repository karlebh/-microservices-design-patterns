<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Caching;

/**
 * Write-through: every write goes to the cache AND the database, in the
 * same operation, before the caller gets its answer.
 *
 * The cache therefore never holds an old value, and a read that follows a
 * write is always a hit. The cost is on the write path: two stores to
 * update every time, including for data that may never be read.
 */
final class WriteThrough
{
    public function __construct(
        private readonly Database $database,
        private readonly Cache $cache,
    ) {}

    public function put(string $key, mixed $value): void
    {
        // Database first: if it fails, the cache must not claim a value that was never stored.
        $this->database->write($key, $value);
        $this->cache->put($key, $value);
    }

    public function get(string $key): mixed
    {
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        // Read-through for entries that were never written via this path
        // (or were evicted to free memory).
        $value = $this->database->read($key);

        if ($value !== null) {
            $this->cache->put($key, $value);
        }

        return $value;
    }
}
