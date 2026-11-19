<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Caching;

use Patterns\Support\Clock;

/** A fast key-value store whose entries expire. */
final class Cache
{
    public int $hits = 0;

    public int $misses = 0;

    /** @var array<string, array{value: mixed, expiresAt: ?int}> */
    private array $entries = [];

    public function __construct(private readonly Clock $clock) {}

    public function get(string $key): mixed
    {
        if (! $this->has($key)) {
            $this->misses++;

            return null;
        }

        $this->hits++;

        return $this->entries[$key]['value'];
    }

    public function has(string $key): bool
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= $this->clock->nowMs()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    /** @param int|null $ttlMs null keeps the entry until it is removed explicitly */
    public function put(string $key, mixed $value, ?int $ttlMs = null): void
    {
        $this->entries[$key] = [
            'value' => $value,
            'expiresAt' => $ttlMs === null ? null : $this->clock->nowMs() + $ttlMs,
        ];
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    /** Milliseconds until the entry expires; null if it is absent or never expires. */
    public function remainingTtlMs(string $key): ?int
    {
        if (! $this->has($key) || $this->entries[$key]['expiresAt'] === null) {
            return null;
        }

        return $this->entries[$key]['expiresAt'] - $this->clock->nowMs();
    }
}
