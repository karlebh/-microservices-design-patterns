<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\CacheStampede;

enum Protection
{
    /** Every request that misses recomputes for itself. */
    case None;

    /** One request recomputes; the others wait for its result. */
    case Lock;

    /** One request recomputes; the others are served the expired value meanwhile. */
    case StaleWhileRevalidate;

    /** Requests randomly refresh shortly before expiry, so the entry never runs out under load. */
    case ProbabilisticEarlyExpiration;
}
