<?php

declare(strict_types=1);

namespace Patterns\Resilience\RateLimiting;

use Patterns\Support\Clock;

/**
 * Sliding window counter.
 *
 * A compromise between the fixed window (cheap, bursty at the boundary) and
 * the sliding log (exact, memory hungry). Keep two counters: the current
 * fixed window and the previous one. Estimate the sliding window as
 *
 *   current count + previous count * (share of the previous window still inside)
 *
 * For example 30% into the current window, 70% of the previous window's
 * requests are assumed to still count. It assumes requests were spread
 * evenly through the previous window, which is close enough in practice.
 */
final class SlidingWindowCounterLimiter implements RateLimiter
{
    /** @var array<string, array{window: int, current: int, previous: int}> */
    private array $counters = [];

    public function __construct(
        private readonly int $limit,
        private readonly int $windowMs,
        private readonly Clock $clock,
    ) {}

    public function allow(string $key): bool
    {
        $now = $this->clock->nowMs();
        $window = intdiv($now, $this->windowMs);

        $state = $this->counters[$key] ?? ['window' => $window, 'current' => 0, 'previous' => 0];

        if ($state['window'] !== $window) {
            // Roll over. If more than one window passed, the "previous" one was empty.
            $state = [
                'window' => $window,
                'current' => 0,
                'previous' => $window - $state['window'] === 1 ? $state['current'] : 0,
            ];
        }

        $elapsedShare = ($now % $this->windowMs) / $this->windowMs;
        $estimated = $state['current'] + $state['previous'] * (1 - $elapsedShare);

        if ($estimated >= $this->limit) {
            $this->counters[$key] = $state;

            return false;
        }

        $state['current']++;
        $this->counters[$key] = $state;

        return true;
    }
}
