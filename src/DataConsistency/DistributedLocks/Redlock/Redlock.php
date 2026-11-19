<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DistributedLocks\Redlock;

use Patterns\Support\Clock;
use RuntimeException;

/**
 * The Redlock algorithm.
 *
 * A lock on a single Redis is a single point of failure: if that node dies
 * (or fails over to a replica that never received the key) the lock is lost
 * or handed out twice. Redlock uses N independent nodes (usually 5):
 *
 *   1. Note the time.
 *   2. Try to set the lock on EVERY node, with the same key and a random token.
 *   3. The lock is acquired only if a MAJORITY said yes AND the time that took
 *      is still well inside the TTL.
 *   4. Its remaining validity = TTL - time spent acquiring - clock drift allowance.
 *   5. If it was not acquired, unlock every node (also those that said yes).
 *
 * Two clients cannot both get a majority of the same N nodes, and the lock
 * survives the loss of a minority of nodes.
 */
final class Redlock
{
    /** Allowance for clocks on different machines running at slightly different speeds. */
    private const CLOCK_DRIFT_FACTOR = 0.01;

    /** @param list<RedisNode> $nodes independent masters (no replication between them) */
    public function __construct(
        private readonly array $nodes,
        private readonly Clock $clock,
    ) {}

    public function acquire(string $resource, int $ttlMs): ?Lock
    {
        $token = bin2hex(random_bytes(16));
        $startedAt = $this->clock->nowMs();

        $acquired = 0;

        foreach ($this->nodes as $node) {
            try {
                if ($node->setIfAbsent($resource, $token, $ttlMs)) {
                    $acquired++;
                }
            } catch (RuntimeException) {
                // Node down: skip it quickly and move on to the next one.
            }
        }

        $elapsedMs = $this->clock->nowMs() - $startedAt;
        $driftMs = (int) ceil($ttlMs * self::CLOCK_DRIFT_FACTOR) + 2;
        $validityMs = $ttlMs - $elapsedMs - $driftMs;

        if ($acquired >= $this->quorum() && $validityMs > 0) {
            return new Lock($resource, $token, $validityMs);
        }

        // Not acquired. Free whatever we did get, or those nodes would
        // block every other client until the TTL runs out.
        $this->unlockEverywhere($resource, $token);

        return null;
    }

    public function release(Lock $lock): void
    {
        $this->unlockEverywhere($lock->resource, $lock->token);
    }

    public function quorum(): int
    {
        return intdiv(count($this->nodes), 2) + 1;
    }

    private function unlockEverywhere(string $resource, string $token): void
    {
        foreach ($this->nodes as $node) {
            try {
                $node->deleteIfValueMatches($resource, $token);
            } catch (RuntimeException) {
                // Unreachable: its key will expire on its own.
            }
        }
    }
}
