<?php

declare(strict_types=1);

namespace Patterns\Resilience\RateLimiting;

use Patterns\Support\Clock;

/**
 * Token bucket.
 *
 * Each client has a bucket holding up to `capacity` tokens. Tokens are
 * added at a steady `refillPerSecond`. A request takes one token; an empty
 * bucket means the request is rejected.
 *
 * Two knobs, two meanings:
 *   refill rate = the sustained rate allowed over time
 *   capacity    = how big a burst is tolerated after a quiet period
 *
 * Nothing actually "drips": the bucket is topped up lazily, from the time
 * elapsed since it was last touched.
 */
final class TokenBucketLimiter implements RateLimiter
{
    /** @var array<string, array{tokens: float, updatedAt: int}> */
    private array $buckets = [];

    public function __construct(
        private readonly int $capacity,
        private readonly float $refillPerSecond,
        private readonly Clock $clock,
    ) {}

    public function allow(string $key): bool
    {
        $now = $this->clock->nowMs();

        // A client we have never seen starts with a full bucket.
        $bucket = $this->buckets[$key] ?? ['tokens' => (float) $this->capacity, 'updatedAt' => $now];

        $elapsedSeconds = ($now - $bucket['updatedAt']) / 1000;
        $bucket['tokens'] = min($this->capacity, $bucket['tokens'] + $elapsedSeconds * $this->refillPerSecond);
        $bucket['updatedAt'] = $now;

        $allowed = $bucket['tokens'] >= 1;

        if ($allowed) {
            $bucket['tokens'] -= 1;
        }

        $this->buckets[$key] = $bucket;

        return $allowed;
    }

    /** How many requests the client could make right now. */
    public function tokens(string $key): int
    {
        return (int) floor($this->buckets[$key]['tokens'] ?? $this->capacity);
    }
}
