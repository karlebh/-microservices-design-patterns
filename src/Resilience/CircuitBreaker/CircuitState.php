<?php

declare(strict_types=1);

namespace Patterns\Resilience\CircuitBreaker;

enum CircuitState: string
{
    /** Normal operation, requests are allowed. */
    case Closed = 'closed';

    /** The dependency is considered unhealthy, requests are blocked. */
    case Open = 'open';

    /** Cooldown is over: one trial request decides whether to close or re-open. */
    case HalfOpen = 'half_open';
}
