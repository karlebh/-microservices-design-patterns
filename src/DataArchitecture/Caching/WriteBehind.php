<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Caching;

/**
 * Write-back / write-behind: a write updates the cache and returns. The
 * database is brought up to date later, in the background, in batches.
 *
 * Writes become as fast as the cache, and repeated writes to the same key
 * collapse into one database write. In exchange the cache temporarily
 * holds the ONLY copy of new data: lose it before the flush and those
 * writes never happened.
 */
final class WriteBehind
{
    /** @var array<string, mixed> key => latest value not yet in the database */
    private array $dirty = [];

    public function __construct(
        private readonly Database $database,
        private readonly Cache $cache,
    ) {}

    public function put(string $key, mixed $value): void
    {
        $this->cache->put($key, $value);

        // Only the newest value per key is kept: older ones are superseded
        // before they ever reach the database.
        $this->dirty[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($key) ?? $this->database->read($key);
    }

    /**
     * The background job: push buffered changes to the database.
     *
     * @return int number of database writes performed
     */
    public function flush(): int
    {
        $flushed = count($this->dirty);

        foreach ($this->dirty as $key => $value) {
            $this->database->write((string) $key, $value);
        }

        $this->dirty = [];

        return $flushed;
    }

    public function pendingWrites(): int
    {
        return count($this->dirty);
    }

    /** The cache node dies before the next flush. */
    public function crash(): void
    {
        $this->dirty = [];
    }
}
