<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DistributedLocks\Chubby;

use Patterns\Support\Clock;
use RuntimeException;

/**
 * A lock service in the style of Google's Chubby (ZooKeeper and etcd work
 * on the same principles).
 *
 * Differences from a "key with a TTL" lock:
 *
 *  - Clients hold a SESSION that they keep alive with heartbeats. Locks belong
 *    to the session. When the session's lease runs out, all its locks are
 *    released — no per-lock TTL guessing.
 *  - Every acquisition bumps the lock's GENERATION and returns a Sequencer.
 *    Downstream servers can reject requests carrying an old generation, which
 *    protects against a holder that was paused and doesn't know it lost the lock.
 *
 * The real service is itself replicated with Paxos so it has no single point
 * of failure; this class models the client-visible behaviour only.
 */
final class LockService
{
    /** @var array<string, int> session id => lease expiry in ms */
    private array $sessions = [];

    /** @var array<string, array{session: string, generation: int}> lock name => current holder */
    private array $held = [];

    /** @var array<string, int> lock name => last generation handed out (never goes down) */
    private array $generations = [];

    private int $sessionCounter = 0;

    public function __construct(
        private readonly Clock $clock,
        private readonly int $leaseMs = 12_000,
    ) {}

    public function openSession(): string
    {
        $id = 'session-'.(++$this->sessionCounter);
        $this->sessions[$id] = $this->clock->nowMs() + $this->leaseMs;

        return $id;
    }

    /** Heartbeat: extends the session lease. Must be called more often than the lease length. */
    public function keepAlive(string $session): void
    {
        $this->expireDeadSessions();
        $this->assertAlive($session);

        $this->sessions[$session] = $this->clock->nowMs() + $this->leaseMs;
    }

    public function tryAcquire(string $session, string $lock): ?Sequencer
    {
        $this->expireDeadSessions();
        $this->assertAlive($session);

        if (isset($this->held[$lock])) {
            return $this->held[$lock]['session'] === $session
                ? new Sequencer($lock, $this->held[$lock]['generation'])
                : null;
        }

        $generation = $this->generations[$lock] = ($this->generations[$lock] ?? 0) + 1;
        $this->held[$lock] = ['session' => $session, 'generation' => $generation];

        return new Sequencer($lock, $generation);
    }

    public function release(string $session, string $lock): void
    {
        if (($this->held[$lock]['session'] ?? null) === $session) {
            unset($this->held[$lock]);
        }
    }

    /**
     * Chubby's CheckSequencer(): is this still the current holder's proof?
     * A resource server calls this (or compares against the highest generation
     * it has seen) before acting on a request.
     */
    public function checkSequencer(Sequencer $sequencer): bool
    {
        $this->expireDeadSessions();

        return ($this->held[$sequencer->lock]['generation'] ?? null) === $sequencer->generation;
    }

    public function holder(string $lock): ?string
    {
        $this->expireDeadSessions();

        return $this->held[$lock]['session'] ?? null;
    }

    private function assertAlive(string $session): void
    {
        if (! isset($this->sessions[$session])) {
            throw new RuntimeException("Session [{$session}] has expired.");
        }
    }

    /** A session whose lease ran out loses everything it held. */
    private function expireDeadSessions(): void
    {
        $now = $this->clock->nowMs();

        foreach ($this->sessions as $session => $expiresAt) {
            if ($expiresAt > $now) {
                continue;
            }

            unset($this->sessions[$session]);

            foreach ($this->held as $lock => $holder) {
                if ($holder['session'] === $session) {
                    unset($this->held[$lock]);
                }
            }
        }
    }
}
