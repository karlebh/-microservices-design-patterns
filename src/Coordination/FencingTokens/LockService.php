<?php

declare(strict_types=1);

namespace Patterns\Coordination\FencingTokens;

use Patterns\Support\Clock;

/**
 * A lock with a timeout that returns a fencing token on every grant.
 *
 * The token is a counter that never repeats and never goes down, so a
 * later holder always has a larger token than every earlier one.
 */
final class LockService
{
    private ?string $holder = null;

    private int $expiresAt = 0;

    /** The last token handed out. */
    private int $token = 0;

    public function __construct(
        private readonly Clock $clock,
        private readonly int $ttlMs = 10_000,
    ) {}

    /**
     * @return int|null the fencing token for this grant, or null if someone else holds the lock
     */
    public function acquire(string $client): ?int
    {
        $expired = $this->clock->nowMs() >= $this->expiresAt;

        if ($this->holder !== null && ! $expired) {
            return null;
        }

        // Granted — possibly while the previous holder still thinks it has the lock.
        $this->holder = $client;
        $this->expiresAt = $this->clock->nowMs() + $this->ttlMs;

        return ++$this->token;
    }

    public function release(string $client): void
    {
        if ($this->holder === $client) {
            $this->holder = null;
        }
    }

    /** Who the lock service believes holds the lock right now. */
    public function holder(): ?string
    {
        return $this->clock->nowMs() >= $this->expiresAt ? null : $this->holder;
    }
}
