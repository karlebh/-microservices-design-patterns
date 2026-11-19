<?php

declare(strict_types=1);

namespace Patterns\Resilience\RetryWithBackoff;

enum JitterStrategy
{
    /** Pure exponential backoff. Clients that failed together retry together. */
    case None;

    /** A random delay between 0 and the exponential value. Spreads load best. */
    case Full;

    /** Half the exponential value, plus a random amount up to the other half. */
    case Equal;

    /** A random delay between the base and three times the previous delay. */
    case Decorrelated;
}
