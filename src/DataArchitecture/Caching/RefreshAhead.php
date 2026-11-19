<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Caching;

/**
 * Refresh-ahead: an entry that is read while it is close to expiring is
 * reloaded in the background, before it expires.
 *
 * A key that keeps being read is therefore always fresh AND always cached:
 * no reader ever pays for the reload. Keys nobody reads are left alone and
 * expire normally.
 */
final class RefreshAhead
{
    /** @var array<string, true> keys waiting for a background refresh */
    private array $refreshQueue = [];

    public int $backgroundRefreshes = 0;

    /**
     * @param  float  $refreshWindow  refresh when less than this share of the TTL remains (0.2 = the last 20%)
     */
    public function __construct(
        private readonly Database $database,
        private readonly Cache $cache,
        private readonly int $ttlMs = 60_000,
        private readonly float $refreshWindow = 0.2,
    ) {}

    public function get(string $key): mixed
    {
        $cached = $this->cache->get($key);

        if ($cached === null) {
            // A genuine miss: the caller waits for the database.
            return $this->load($key);
        }

        if ($this->cache->remainingTtlMs($key) < $this->ttlMs * $this->refreshWindow) {
            // About to expire and evidently still in demand: ask for a
            // refresh, but answer NOW with the value we have.
            $this->refreshQueue[$key] = true;
        }

        return $cached;
    }

    /** The background worker: reload every key that was flagged. */
    public function runBackgroundRefreshes(): void
    {
        foreach (array_keys($this->refreshQueue) as $key) {
            $this->load((string) $key);
            $this->backgroundRefreshes++;
        }

        $this->refreshQueue = [];
    }

    private function load(string $key): mixed
    {
        $value = $this->database->read($key);

        if ($value !== null) {
            $this->cache->put($key, $value, $this->ttlMs);
        }

        return $value;
    }
}
