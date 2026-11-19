<?php

declare(strict_types=1);

namespace Patterns\Coordination\Gossip;

/**
 * What one node believes about the world. It only ever learns things by
 * exchanging state with one peer at a time.
 */
final class GossipNode
{
    /** @var array<string, array{value: mixed, version: int}> shared data: the highest version of each key wins */
    public array $data = [];

    /** @var array<string, array{heartbeat: int, lastChangedRound: int}> the membership table */
    public array $members = [];

    /** A crashed node neither sends nor answers. */
    public bool $crashed = false;

    public function __construct(public readonly string $id)
    {
        $this->members[$id] = ['heartbeat' => 0, 'lastChangedRound' => 0];
    }

    /** Announce "I am still here" by bumping this node's own counter. */
    public function beat(int $round): void
    {
        $this->members[$this->id] = ['heartbeat' => $this->members[$this->id]['heartbeat'] + 1, 'lastChangedRound' => $round];
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = ['value' => $value, 'version' => ($this->data[$key]['version'] ?? 0) + 1];
    }

    /** Merge what a peer told us into our own view. */
    public function receive(self $peer, int $round): void
    {
        foreach ($peer->data as $key => $entry) {
            if ($entry['version'] > ($this->data[$key]['version'] ?? 0)) {
                $this->data[$key] = $entry;
            }
        }

        foreach ($peer->members as $id => $entry) {
            // A higher counter is proof that the node was alive more recently than we thought.
            if ($entry['heartbeat'] > ($this->members[$id]['heartbeat'] ?? -1)) {
                $this->members[$id] = ['heartbeat' => $entry['heartbeat'], 'lastChangedRound' => $round];
            }
        }
    }

    /**
     * Members this node currently believes to be alive: those whose
     * heartbeat it has seen advance within the last $failAfterRounds rounds.
     *
     * @return list<string>
     */
    public function aliveMembers(int $round, int $failAfterRounds): array
    {
        $alive = array_keys(array_filter(
            $this->members,
            fn (array $entry) => $round - $entry['lastChangedRound'] < $failAfterRounds,
        ));

        sort($alive);

        return $alive;
    }
}
