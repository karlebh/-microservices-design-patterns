<?php

declare(strict_types=1);

namespace Patterns\Resilience\RateLimiting;

interface RateLimiter
{
    /**
     * Record one request for $key (a user id, API key, IP address...) and
     * say whether it is within the limit.
     */
    public function allow(string $key): bool;
}
