<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ReadReplicas\Simulation;

/**
 * A read-only copy that follows the primary asynchronously.
 *
 * Until catchUp() runs, the replica simply doesn't know about recent writes.
 * That gap is replication lag.
 */
final class Replica
{
    /** @var array<string, mixed> */
    private array $data = [];

    private int $appliedLsn = 0;

    public function __construct(
        public readonly string $name,
        private readonly Primary $primary,
    ) {}

    /**
     * Pull and apply pending changes, in log order.
     *
     * @param  int|null  $maxChanges  apply at most this many (simulates a slow replica)
     * @return int how many changes were applied
     */
    public function catchUp(?int $maxChanges = null): int
    {
        $changes = $this->primary->changesAfter($this->appliedLsn, $maxChanges);

        foreach ($changes as $change) {
            if ($change['op'] === 'set') {
                $this->data[$change['key']] = $change['value'];
            } else {
                unset($this->data[$change['key']]);
            }

            $this->appliedLsn = $change['lsn'];
        }

        return count($changes);
    }

    public function read(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function appliedLsn(): int
    {
        return $this->appliedLsn;
    }

    /** How many writes behind the primary this replica is. */
    public function lag(): int
    {
        return $this->primary->lsn() - $this->appliedLsn;
    }
}
