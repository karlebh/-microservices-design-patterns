<?php

declare(strict_types=1);

namespace Patterns\Resilience\RateLimiting;

use Patterns\Support\Clock;

/**
 * Leaky bucket (used as a meter).
 *
 * Picture a bucket with a hole in the bottom. Every request pours one unit
 * of water in; water leaks out at a constant `leakPerSecond`. If a request
 * would overflow the bucket, it is rejected.
 *
 * What gets through downstream therefore never exceeds the leak rate for
 * long, regardless of how bursty the arrivals are. Compare with the token
 * bucket: an idle period does NOT earn the client a big burst here — the
 * bucket only ever holds `capacity`, and it empties at the same fixed rate.
 */
final class LeakyBucketLimiter implements RateLimiter
{
    /** @var array<string, array{level: float, updatedAt: int}> */
    private array $buckets = [];

    public function __construct(
        private readonly int $capacity,
        private readonly float $leakPerSecond,
        private readonly Clock $clock,
    ) {}

    public function allow(string $key): bool
    {
        $now = $this->clock->nowMs();
        $bucket = $this->buckets[$key] ?? ['level' => 0.0, 'updatedAt' => $now];

        // Let the water that leaked out since last time drain away.
        $elapsedSeconds = ($now - $bucket['updatedAt']) / 1000;
        $bucket['level'] = max(0.0, $bucket['level'] - $elapsedSeconds * $this->leakPerSecond);
        $bucket['updatedAt'] = $now;

        $allowed = $bucket['level'] + 1 <= $this->capacity;

        if ($allowed) {
            $bucket['level'] += 1;
        }

        $this->buckets[$key] = $bucket;

        return $allowed;
    }

    /** How full the bucket is right now. */
    public function level(string $key): float
    {
        return $this->buckets[$key]['level'] ?? 0.0;
    }
}
