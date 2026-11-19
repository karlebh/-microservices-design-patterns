<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ReadReplicas\Simulation;

/**
 * Sends writes to the primary and spreads reads over the replicas.
 *
 * One router instance represents one client session, so it can remember
 * what that client has written and keep its reads consistent with it.
 */
final class ReplicaRouter
{
    /** Whether the current request has written anything (drives StickyPrimary). */
    private bool $wroteInThisRequest = false;

    /** Log position of this session's most recent write (drives ReadYourWrites). */
    private int $lastWriteLsn = 0;

    private int $nextReplica = 0;

    /** Where each read was served from, for inspection in tests. */
    public array $readsServedBy = [];

    /** @param list<Replica> $replicas */
    public function __construct(
        private readonly Primary $primary,
        private readonly array $replicas,
        private readonly ReadConsistency $consistency = ReadConsistency::Eventual,
    ) {}

    public function write(string $key, mixed $value): void
    {
        $this->lastWriteLsn = $this->primary->write($key, $value);
        $this->wroteInThisRequest = true;
    }

    public function read(string $key): mixed
    {
        if ($this->consistency === ReadConsistency::StickyPrimary && $this->wroteInThisRequest) {
            return $this->fromPrimary($key);
        }

        if ($this->consistency === ReadConsistency::ReadYourWrites) {
            // Any replica that has applied our last write is safe to read from.
            foreach ($this->replicas as $replica) {
                if ($replica->appliedLsn() >= $this->lastWriteLsn) {
                    return $this->fromReplica($replica, $key);
                }
            }

            // Nobody has caught up yet: only the primary has our write.
            return $this->fromPrimary($key);
        }

        // Round-robin across replicas.
        $replica = $this->replicas[$this->nextReplica++ % count($this->replicas)];

        return $this->fromReplica($replica, $key);
    }

    /** A new HTTP request begins: stickiness does not carry over, the LSN token does. */
    public function beginRequest(): void
    {
        $this->wroteInThisRequest = false;
    }

    private function fromPrimary(string $key): mixed
    {
        $this->readsServedBy[] = 'primary';

        return $this->primary->read($key);
    }

    private function fromReplica(Replica $replica, string $key): mixed
    {
        $this->readsServedBy[] = $replica->name;

        return $replica->read($key);
    }
}
