<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DistributedLocks\Redlock;

/** A lock we currently hold. */
final readonly class Lock
{
    public function __construct(
        public string $resource,
        /** Random value only the holder knows; required to unlock. */
        public string $token,
        /** How long the lock can be trusted for, counted from when it was returned. */
        public int $validityMs,
    ) {}
}
