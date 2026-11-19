<?php

declare(strict_types=1);

namespace Patterns\Support;

/**
 * Time source used by every pattern that depends on time (TTLs, leases,
 * timeouts, token buckets, heartbeats...).
 *
 * Patterns never call time() or sleep() directly. They ask a Clock, so tests
 * can swap in a FakeClock and "wait 30 seconds" instantly and deterministically.
 */
interface Clock
{
    /** Milliseconds since an arbitrary fixed point. Only differences are meaningful. */
    public function nowMs(): int;
}
