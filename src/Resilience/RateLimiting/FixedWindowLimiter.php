<?php

declare(strict_types=1);

namespace Patterns\Resilience\RateLimiting;

use Patterns\Support\Clock;

/**
 * Fixed window counter.
 *
 * Time is cut into fixed windows (00:00-00:59, 01:00-01:59...). Each window
 * has a counter that starts at zero.
 *
 * Simple and cheap, but the windows are aligned to the clock, not to the
 * client: `limit` requests at the end of one window followed by `limit`
 * at the start of the next are all accepted, i.e. twice the limit in a
 * moment.
 */
final class FixedWindowLimiter implements RateLimiter
{
    /** @var array<string, array{window: int, count: int}> */
    private array $counters = [];

    public function __construct(
        private readonly int $limit,
        private readonly int $windowMs,
        private readonly Clock $clock,
    ) {}

    public function allow(string $key): bool
    {
        $window = intdiv($this->clock->nowMs(), $this->windowMs);

        // A new window has started: forget the old count.
        if (($this->counters[$key]['window'] ?? null) !== $window) {
            $this->counters[$key] = ['window' => $window, 'count' => 0];
        }

        if ($this->counters[$key]['count'] >= $this->limit) {
            return false;
        }

        $this->counters[$key]['count']++;

        return true;
    }
}
