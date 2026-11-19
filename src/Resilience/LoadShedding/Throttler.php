<?php

declare(strict_types=1);

namespace Patterns\Resilience\LoadShedding;

use Patterns\Support\Clock;

/**
 * Request throttling: hold each client to its own rate, and tell a client
 * that goes over exactly how long to wait.
 *
 * Unlike the load shedder, this has nothing to do with how busy the server
 * is. A client can be throttled on an idle server; that is the point —
 * capacity is divided fairly instead of going to whoever shouts loudest.
 *
 * The mechanism is a token bucket per client (see ../RateLimiting).
 */
final class Throttler
{
    /** @var array<string, array{tokens: float, updatedAt: int}> */
    private array $buckets = [];

    /**
     * @param  float  $requestsPerSecond  the sustained rate each client may use
     * @param  int  $burst  how many requests a client may make at once after being quiet
     */
    public function __construct(
        private readonly float $requestsPerSecond,
        private readonly int $burst,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{allowed: bool, remaining: int, retryAfterMs: int}
     *                                                                 `retryAfterMs` is what goes into the Retry-After header of a 429
     */
    public function attempt(string $client): array
    {
        $now = $this->clock->nowMs();
        $bucket = $this->buckets[$client] ?? ['tokens' => (float) $this->burst, 'updatedAt' => $now];

        $bucket['tokens'] = min(
            $this->burst,
            $bucket['tokens'] + ($now - $bucket['updatedAt']) / 1000 * $this->requestsPerSecond,
        );
        $bucket['updatedAt'] = $now;

        $allowed = $bucket['tokens'] >= 1;

        if ($allowed) {
            $bucket['tokens'] -= 1;
        }

        $this->buckets[$client] = $bucket;

        return [
            'allowed' => $allowed,
            'remaining' => (int) floor($bucket['tokens']),
            // Time until the bucket holds one whole token again.
            'retryAfterMs' => $allowed ? 0 : (int) ceil((1 - $bucket['tokens']) / $this->requestsPerSecond * 1000),
        ];
    }
}
