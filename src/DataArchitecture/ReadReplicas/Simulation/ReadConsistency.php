<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ReadReplicas\Simulation;

enum ReadConsistency
{
    /** Any replica. Fastest and cheapest, but may return stale data. */
    case Eventual;

    /** After writing, read from the primary for the rest of the request. Laravel's `sticky` option. */
    case StickyPrimary;

    /** Read only from a replica that has caught up to this session's last write. */
    case ReadYourWrites;
}
