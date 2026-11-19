<?php

declare(strict_types=1);

namespace Patterns\Coordination\HeartbeatsAndLeases;

use Patterns\Support\Clock;

/**
 * Grants leases: exclusive ownership of a resource for a limited time.
 *
 * The holder keeps the lease by renewing it. If it stops (crash, partition)
 * the lease expires on its own and the resource becomes available, with no
 * need for anyone to decide that the holder is dead.
 */
final class LeaseManager
{
    /** @var array<string, array{holder: string, expiresAt: int}> */
    private array $leases = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $durationMs = 10_000,
    ) {}

    /**
     * @return int|null when the lease expires, or null if someone else holds it
     */
    public function acquire(string $resource, string $holder): ?int
    {
        $current = $this->current($resource);

        if ($current !== null && $current['holder'] !== $holder) {
            return null;
        }

        $this->leases[$resource] = ['holder' => $holder, 'expiresAt' => $this->clock->nowMs() + $this->durationMs];

        return $this->leases[$resource]['expiresAt'];
    }

    /**
     * Extend the lease. Only works while it is still valid: an expired
     * lease may already belong to someone else.
     *
     * @return int|null the new expiry time, or null if the lease was lost
     */
    public function renew(string $resource, string $holder): ?int
    {
        $stillHeld = ($this->current($resource)['holder'] ?? null) === $holder;

        return $stillHeld ? $this->acquire($resource, $holder) : null;
    }

    public function release(string $resource, string $holder): void
    {
        if (($this->current($resource)['holder'] ?? null) === $holder) {
            unset($this->leases[$resource]);
        }
    }

    /** Who owns the resource right now, according to the grantor's clock. */
    public function holder(string $resource): ?string
    {
        return $this->current($resource)['holder'] ?? null;
    }

    /**
     * The check a HOLDER should make before acting on its lease.
     *
     * It deliberately treats the lease as over a little early. The holder's
     * clock may run slower than the grantor's, so "my clock says I have
     * 50 ms left" is not safe to act on. The margin absorbs that drift.
     */
    public function mayAct(string $resource, string $holder, int $safetyMarginMs = 1_000): bool
    {
        $current = $this->current($resource);

        return $current !== null
            && $current['holder'] === $holder
            && $this->clock->nowMs() < $current['expiresAt'] - $safetyMarginMs;
    }

    /** @return array{holder: string, expiresAt: int}|null */
    private function current(string $resource): ?array
    {
        $lease = $this->leases[$resource] ?? null;

        if ($lease === null || $this->clock->nowMs() >= $lease['expiresAt']) {
            return null; // never granted, or expired
        }

        return $lease;
    }
}
