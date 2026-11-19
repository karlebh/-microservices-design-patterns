<?php

declare(strict_types=1);

namespace Patterns\Resilience\LoadShedding;

enum Priority
{
    /** Never drop unless the server is completely full: checkout, payments, health checks. */
    case Critical;

    /** Ordinary user traffic: browsing, search. */
    case Normal;

    /** Nice to have: prefetching, analytics beacons, background refreshes. First to go. */
    case Sheddable;
}
