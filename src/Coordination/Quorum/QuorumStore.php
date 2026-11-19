<?php

declare(strict_types=1);

namespace Patterns\Coordination\Quorum;

use RuntimeException;

/**
 * A key-value store replicated on N nodes, with tunable quorums
 * (the model used by Dynamo, Cassandra and Riak).
 *
 * A write succeeds once W replicas have it. A read asks R replicas and
 * returns the value with the highest version. When R + W > N the two sets
 * must overlap, so the read is guaranteed to include the latest write.
 */
final class QuorumStore
{
    /** @var list<array<string, array{value: mixed, version: int}>> one map per replica */
    private array $replicas;

    /** @var list<bool> */
    private array $up;

    /** @var array<int, list<array{key: string, value: mixed, version: int}>> replica => writes kept for it while it was away */
    private array $hints = [];

    public int $readRepairs = 0;

    /**
     * @param  bool  $sloppy  let other nodes stand in for unreachable replicas (hinted handoff)
     */
    public function __construct(
        private readonly int $n,
        private readonly int $w,
        private readonly int $r,
        private readonly bool $sloppy = false,
    ) {
        $this->replicas = array_fill(0, $n, []);
        $this->up = array_fill(0, $n, true);
    }

    /** With R + W > N every read quorum overlaps every write quorum. */
    public function guaranteesFreshReads(): bool
    {
        return $this->r + $this->w > $this->n;
    }

    /**
     * @param  list<int>|null  $reachable  replicas this request manages to contact (default: all that are up)
     * @return bool false when fewer than W replicas acknowledged: the write is rejected
     */
    public function write(string $key, mixed $value, ?array $reachable = null): bool
    {
        $targets = $this->available($reachable);
        $missed = array_values(array_diff(range(0, $this->n - 1), $targets));

        $acknowledgements = count($targets) + ($this->sloppy ? count($missed) : 0);

        if ($acknowledgements < $this->w) {
            return false;
        }

        $version = 1 + max(0, ...array_map(fn (int $i) => $this->replicas[$i][$key]['version'] ?? 0, $targets));

        foreach ($targets as $i) {
            $this->replicas[$i][$key] = ['value' => $value, 'version' => $version];
        }

        if ($this->sloppy) {
            // A stand-in node keeps the write for each replica that could not be reached.
            foreach ($missed as $i) {
                $this->hints[$i][] = ['key' => $key, 'value' => $value, 'version' => $version];
            }
        }

        return true;
    }

    /**
     * @param  list<int>|null  $reachable  replicas this request manages to contact, in the order they answer
     *
     * @throws RuntimeException when fewer than R replicas answer
     */
    public function read(string $key, ?array $reachable = null): mixed
    {
        $targets = $this->available($reachable);

        if (count($targets) < $this->r) {
            throw new RuntimeException("Only ".count($targets)." replicas answered; {$this->r} are required.");
        }

        $asked = array_slice($targets, 0, $this->r);
        $newest = ['value' => null, 'version' => 0];

        foreach ($asked as $i) {
            if (($this->replicas[$i][$key]['version'] ?? 0) > $newest['version']) {
                $newest = $this->replicas[$i][$key];
            }
        }

        // Read repair: the reader noticed stale copies, so it fixes them on the way out.
        foreach ($asked as $i) {
            if ($newest['version'] > 0 && ($this->replicas[$i][$key]['version'] ?? 0) < $newest['version']) {
                $this->replicas[$i][$key] = $newest;
                $this->readRepairs++;
            }
        }

        return $newest['value'];
    }

    public function fail(int $replica): void
    {
        $this->up[$replica] = false;
    }

    /** The replica comes back; any writes that were held for it are handed over. */
    public function recover(int $replica): void
    {
        $this->up[$replica] = true;

        foreach ($this->hints[$replica] ?? [] as $hint) {
            if ($hint['version'] > ($this->replicas[$replica][$hint['key']]['version'] ?? 0)) {
                $this->replicas[$replica][$hint['key']] = ['value' => $hint['value'], 'version' => $hint['version']];
            }
        }

        unset($this->hints[$replica]);
    }

    /** What one particular replica holds (for inspection). */
    public function valueOn(int $replica, string $key): mixed
    {
        return $this->replicas[$replica][$key]['value'] ?? null;
    }

    public function pendingHints(): int
    {
        return array_sum(array_map(count(...), $this->hints));
    }

    /**
     * @param  list<int>|null  $reachable
     * @return list<int>
     */
    private function available(?array $reachable): array
    {
        return array_values(array_filter(
            $reachable ?? range(0, $this->n - 1),
            fn (int $i) => $this->up[$i],
        ));
    }
}
