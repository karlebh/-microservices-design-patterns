<?php

declare(strict_types=1);

namespace Patterns\Coordination\LeaderElection;

/**
 * The ZooKeeper leader-election recipe.
 *
 * Under a parent node (say /election) every candidate creates a child that is
 *   - SEQUENTIAL: ZooKeeper appends an ever-increasing number to its name
 *   - EPHEMERAL:  it is deleted automatically when the creator's session ends
 *
 * The candidate holding the lowest number is the leader. Everyone else
 * watches only the node immediately before its own.
 *
 * This class models the znodes and watches; in the real thing ZooKeeper
 * provides them, replicated and ordered by its Zab consensus protocol.
 */
final class ZooKeeperElection
{
    /** @var array<int, string> sequence number => candidate that owns that znode */
    private array $znodes = [];

    private int $nextSequence = 0;

    /** @var list<string> candidates that received a watch notification, in order */
    public array $notified = [];

    /** @return int the sequence number the candidate was given */
    public function join(string $candidate): int
    {
        $this->znodes[$this->nextSequence] = $candidate;

        return $this->nextSequence++;
    }

    /** The owner of the lowest-numbered znode. */
    public function leader(): ?string
    {
        return $this->znodes === [] ? null : $this->znodes[min(array_keys($this->znodes))];
    }

    /**
     * The candidate this one watches: whoever is directly ahead of it in
     * the queue. The leader watches nobody.
     */
    public function watchedBy(string $candidate): ?string
    {
        $own = array_search($candidate, $this->znodes, true);
        $ahead = array_filter(array_keys($this->znodes), fn (int $sequence) => $sequence < $own);

        return $ahead === [] ? null : $this->znodes[max($ahead)];
    }

    /**
     * The candidate's session ended (it crashed or was partitioned away).
     * ZooKeeper removes its ephemeral znode and fires the single watch set on it.
     */
    public function sessionExpired(string $candidate): void
    {
        $sequence = array_search($candidate, $this->znodes, true);

        if ($sequence === false) {
            return;
        }

        // Who was watching the node that is about to disappear?
        $behind = array_filter(array_keys($this->znodes), fn (int $other) => $other > $sequence);
        $watcher = $behind === [] ? null : $this->znodes[min($behind)];

        unset($this->znodes[$sequence]);

        if ($watcher !== null) {
            // Exactly one candidate wakes up. It re-reads the children: either it is
            // now the lowest (it leads), or it starts watching its new predecessor.
            $this->notified[] = $watcher;
        }
    }

    /** @return list<string> candidates in queue order */
    public function queue(): array
    {
        ksort($this->znodes);

        return array_values($this->znodes);
    }
}
