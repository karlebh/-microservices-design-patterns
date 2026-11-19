<?php

declare(strict_types=1);

namespace Patterns\Resilience\RateLimiting;

use Patterns\Support\Clock;

/**
 * Sliding window log.
 *
 * Remember the timestamp of every accepted request. A new request is
 * allowed if fewer than `limit` timestamps fall within the last `windowMs`.
 *
 * The window moves with each request, so there is no boundary to exploit:
 * the limit holds for ANY interval of that length. The cost is memory —
 * one timestamp per request — which is why it is used for low limits
 * (login attempts) rather than high ones.
 */
final class SlidingWindowLogLimiter implements RateLimiter
{
    /** @var array<string, list<int>> accepted request times, oldest first */
    private array $log = [];

    public function __construct(
        private readonly int $limit,
        private readonly int $windowMs,
        private readonly Clock $clock,
    ) {}

    public function allow(string $key): bool
    {
        $now = $this->clock->nowMs();
        $windowStart = $now - $this->windowMs;

        // Drop timestamps that have slid out of the window.
        $this->log[$key] = array_values(array_filter(
            $this->log[$key] ?? [],
            fn (int $timestamp) => $timestamp > $windowStart,
        ));

        if (count($this->log[$key]) >= $this->limit) {
            return false;
        }

        $this->log[$key][] = $now;

        return true;
    }
}
