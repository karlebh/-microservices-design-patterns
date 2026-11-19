<?php

declare(strict_types=1);

namespace Patterns\Resilience\Failover;

use Patterns\Support\Clock;
use RuntimeException;

/**
 * Active-passive: one primary takes every write, standbys follow it and
 * take over if it disappears.
 */
final class ActivePassiveCluster
{
    /** @var array<string, array<string, mixed>> node => its copy of the data */
    private array $data = [];

    /** @var array<string, int> node => time of its last heartbeat */
    private array $lastHeartbeat = [];

    /** @var array<string, list<array{key: string, value: mixed}>> standby => writes it has not received yet */
    private array $replicationBacklog = [];

    private string $primary;

    /** Incremented on every promotion. Identifies "which reign" a write belongs to. */
    private int $epoch = 1;

    /** @var list<string> keys of writes that were acknowledged to clients and then lost in a failover */
    public array $lostWrites = [];

    /**
     * @param  list<string>  $nodes  the first one starts as primary
     * @param  bool  $synchronous  true: a write is acknowledged only after the standbys have it
     */
    public function __construct(
        array $nodes,
        private readonly Clock $clock,
        private readonly int $heartbeatTimeoutMs = 5_000,
        private readonly bool $synchronous = false,
    ) {
        $this->primary = $nodes[0];

        foreach ($nodes as $node) {
            $this->data[$node] = [];
            $this->lastHeartbeat[$node] = $clock->nowMs();
            $this->replicationBacklog[$node] = [];
        }
    }

    // ---- Client operations -------------------------------------------------------------

    /** A normal client write: always handled by whoever is primary now. */
    public function write(string $key, mixed $value): void
    {
        $this->writeAs($this->primary, $this->epoch, $key, $value);
    }

    /**
     * A write issued by a specific node that believes it is primary in a given epoch.
     *
     * @throws RuntimeException when that belief is out of date (the node has been replaced)
     */
    public function writeAs(string $node, int $epoch, string $key, mixed $value): void
    {
        // Fencing: a deposed primary still holds an old epoch number.
        if ($node !== $this->primary || $epoch !== $this->epoch) {
            throw new RuntimeException("[{$node}] is not the primary of epoch {$this->epoch} (it claimed epoch {$epoch}).");
        }

        $this->data[$node][$key] = $value;

        foreach ($this->standbys() as $standby) {
            $this->replicationBacklog[$standby][] = ['key' => $key, 'value' => $value];
        }

        if ($this->synchronous) {
            $this->replicate(); // the client waits for this before getting its "ok"
        }
    }

    public function read(string $key): mixed
    {
        return $this->data[$this->primary][$key] ?? null;
    }

    // ---- Replication -------------------------------------------------------------------

    /** Ship pending writes to the standbys. With async replication this runs some time after the write. */
    public function replicate(): void
    {
        foreach ($this->standbys() as $standby) {
            foreach ($this->replicationBacklog[$standby] as $write) {
                $this->data[$standby][$write['key']] = $write['value'];
            }

            $this->replicationBacklog[$standby] = [];
        }
    }

    // ---- Failure detection and failover ------------------------------------------------

    public function heartbeat(string $node): void
    {
        $this->lastHeartbeat[$node] = $this->clock->nowMs();
    }

    /**
     * Run by the cluster manager periodically. If the primary has been
     * silent for longer than the timeout, promote a standby.
     *
     * @return bool true if a failover happened
     */
    public function checkPrimary(): bool
    {
        if ($this->clock->nowMs() - $this->lastHeartbeat[$this->primary] <= $this->heartbeatTimeoutMs) {
            return false;
        }

        $this->failover();

        return true;
    }

    private function failover(): void
    {
        $failed = $this->primary;
        $standbys = $this->standbys();

        // Promote the standby that is missing the fewest writes.
        usort($standbys, fn (string $a, string $b) => count($this->replicationBacklog[$a]) <=> count($this->replicationBacklog[$b]));
        $promoted = $standbys[0];

        // Whatever the old primary never shipped to the new one is gone: this is the RPO.
        $this->lostWrites = array_column($this->replicationBacklog[$promoted], 'key');

        $this->primary = $promoted;
        $this->epoch++; // a new reign: everything the old primary believed is now stale

        // The failed node is no longer a source of truth. If it returns, it returns as a standby.
        $this->replicationBacklog[$failed] = [];
        $this->replicationBacklog[$promoted] = [];
        $this->lastHeartbeat[$promoted] = $this->clock->nowMs();
    }

    /** A failed node comes back: it copies the current primary and follows it. */
    public function rejoin(string $node): void
    {
        $this->data[$node] = $this->data[$this->primary];
        $this->replicationBacklog[$node] = [];
        $this->heartbeat($node);
    }

    public function primary(): string
    {
        return $this->primary;
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    /** @return list<string> */
    private function standbys(): array
    {
        return array_values(array_diff(array_keys($this->data), [$this->primary]));
    }
}
