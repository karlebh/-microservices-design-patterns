<?php

declare(strict_types=1);

namespace Patterns\Coordination\TombstonesAndVersionVectors;

use Patterns\Support\Clock;

/**
 * One replica of a leaderless key-value store.
 *
 * Every replica accepts writes. Replicas converge by exchanging their
 * contents (anti-entropy) and merging per key with version vectors:
 * a version that is dominated by another is discarded; versions that are
 * concurrent are all kept, as siblings.
 */
final class Replica
{
    /**
     * @var array<string, list<array{value: mixed, deleted: bool, version: VersionVector, deletedAt: ?int}>>
     *                                                                                                   key => its current version(s)
     */
    private array $data = [];

    /**
     * @param  bool  $useTombstones  false = delete by simply removing the row (to show why that fails)
     * @param  int  $gcGraceMs  how long a tombstone is kept before it may be purged
     */
    public function __construct(
        public readonly string $id,
        private readonly Clock $clock,
        private readonly bool $useTombstones = true,
        private readonly int $gcGraceMs = 10_000,
    ) {}

    /**
     * Write a value. The writer is assumed to have seen what this replica
     * holds, so the new version supersedes all current siblings.
     */
    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = [[
            'value' => $value,
            'deleted' => false,
            'version' => $this->nextVersion($key),
            'deletedAt' => null,
        ]];
    }

    public function delete(string $key): void
    {
        if (! $this->useTombstones) {
            unset($this->data[$key]); // leaves no trace that a delete ever happened

            return;
        }

        // A delete is a write of a special value. It has a version like any
        // other, so it can travel to other replicas and win there.
        $this->data[$key] = [[
            'value' => null,
            'deleted' => true,
            'version' => $this->nextVersion($key),
            'deletedAt' => $this->clock->nowMs(),
        ]];
    }

    /**
     * @return list<mixed> the live value(s). Zero = absent or deleted, one = normal,
     *                     several = concurrent writes the application must reconcile
     */
    public function get(string $key): array
    {
        return array_values(array_map(
            fn (array $entry) => $entry['value'],
            array_filter($this->data[$key] ?? [], fn (array $entry) => ! $entry['deleted']),
        ));
    }

    public function hasTombstone(string $key): bool
    {
        return array_filter($this->data[$key] ?? [], fn (array $entry) => $entry['deleted']) !== [];
    }

    /**
     * Anti-entropy: pull everything the other replica has and merge it in.
     */
    public function syncFrom(self $other): void
    {
        foreach ($other->data as $key => $theirs) {
            $candidates = [...$this->data[$key] ?? [], ...$theirs];
            $survivors = [];

            foreach ($candidates as $i => $candidate) {
                $superseded = false;

                foreach ($candidates as $j => $rival) {
                    $identical = $candidate['version']->equals($rival['version']);

                    // Drop a version if another one strictly descends from it,
                    // and keep only the first of two identical copies.
                    if (($rival['version']->dominates($candidate['version']) && ! $identical) || ($identical && $j < $i)) {
                        $superseded = true;

                        break;
                    }
                }

                if (! $superseded) {
                    $survivors[] = $candidate;
                }
            }

            $this->data[$key] = $survivors;
        }
    }

    /**
     * Garbage-collect tombstones older than the grace period.
     *
     * @return int how many were removed
     */
    public function purgeTombstones(): int
    {
        $purged = 0;

        foreach ($this->data as $key => $entries) {
            foreach ($entries as $i => $entry) {
                if ($entry['deleted'] && $this->clock->nowMs() - $entry['deletedAt'] >= $this->gcGraceMs) {
                    unset($this->data[$key][$i]);
                    $purged++;
                }
            }

            if ($this->data[$key] === []) {
                unset($this->data[$key]);
            }
        }

        return $purged;
    }

    /** A version that descends from everything this replica currently holds for the key. */
    private function nextVersion(string $key): VersionVector
    {
        $version = new VersionVector;

        foreach ($this->data[$key] ?? [] as $entry) {
            $version = $version->merge($entry['version']);
        }

        return $version->increment($this->id);
    }
}
