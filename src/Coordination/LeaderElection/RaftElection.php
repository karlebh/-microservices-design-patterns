<?php

declare(strict_types=1);

namespace Patterns\Coordination\LeaderElection;

/**
 * Leader election the way Raft does it: numbered terms, one vote per node
 * per term, and a majority needed to win.
 *
 * (This is only the election half of Raft. For the replicated log see
 * ../Consensus.)
 */
final class RaftElection
{
    /** @var array<string, array{term: int, votedFor: ?string, role: string}> */
    private array $nodes = [];

    /** @var array<string, int> node => which side of a partition it is on */
    private array $side = [];

    /** @param list<string> $nodeIds */
    public function __construct(array $nodeIds)
    {
        foreach ($nodeIds as $id) {
            $this->nodes[$id] = ['term' => 0, 'votedFor' => null, 'role' => 'follower'];
            $this->side[$id] = 0;
        }
    }

    /**
     * $candidate's election timer ran out: it starts a new term and asks
     * every node it can reach for a vote.
     *
     * @return bool true if it won a majority and is now leader
     */
    public function campaign(string $candidate): bool
    {
        $term = ++$this->nodes[$candidate]['term'];
        $this->nodes[$candidate]['votedFor'] = $candidate;
        $this->nodes[$candidate]['role'] = 'candidate';

        $votes = 1; // its own

        foreach ($this->reachableFrom($candidate) as $peer) {
            if ($this->requestVote($peer, $candidate, $term)) {
                $votes++;
            }
        }

        if ($votes <= count($this->nodes) / 2) {
            return false; // no majority: stays a candidate until a later term
        }

        $this->nodes[$candidate]['role'] = 'leader';

        // A new leader immediately asserts itself; reachable nodes follow it.
        $this->heartbeat($candidate);

        return true;
    }

    /**
     * The leader's periodic message. Any node that sees a term at least as
     * high as its own accepts the sender as leader; a leader or candidate
     * with an OLDER term thereby learns that it has been superseded.
     */
    public function heartbeat(string $leader): void
    {
        $term = $this->nodes[$leader]['term'];

        foreach ($this->reachableFrom($leader) as $peer) {
            if ($this->nodes[$peer]['term'] <= $term) {
                $this->nodes[$peer]['term'] = $term;
                $this->nodes[$peer]['role'] = 'follower';
            }
        }
    }

    /** @param list<string> ...$sides e.g. partition(['a', 'b'], ['c', 'd', 'e']) */
    public function partition(array ...$sides): void
    {
        foreach ($sides as $index => $members) {
            foreach ($members as $id) {
                $this->side[$id] = $index;
            }
        }
    }

    public function heal(): void
    {
        $this->side = array_fill_keys(array_keys($this->nodes), 0);
    }

    public function role(string $node): string
    {
        return $this->nodes[$node]['role'];
    }

    public function term(string $node): int
    {
        return $this->nodes[$node]['term'];
    }

    /** @return array<int, list<string>> term => nodes that consider themselves leader of that term */
    public function leadersByTerm(): array
    {
        $leaders = [];

        foreach ($this->nodes as $id => $node) {
            if ($node['role'] === 'leader') {
                $leaders[$node['term']][] = $id;
            }
        }

        ksort($leaders);

        return $leaders;
    }

    private function requestVote(string $voter, string $candidate, int $term): bool
    {
        $node = &$this->nodes[$voter];

        // A higher term always wins: the voter moves into it, forgetting who it voted for before.
        if ($term > $node['term']) {
            $node['term'] = $term;
            $node['votedFor'] = null;
            $node['role'] = 'follower';
        }

        // One vote per term, first come first served. This is the rule that
        // makes two leaders in the same term impossible.
        if ($term === $node['term'] && $node['votedFor'] === null) {
            $node['votedFor'] = $candidate;

            return true;
        }

        return false;
    }

    /** @return list<string> */
    private function reachableFrom(string $node): array
    {
        return array_values(array_filter(
            array_keys($this->nodes),
            fn (string $other) => $other !== $node && $this->side[$other] === $this->side[$node],
        ));
    }
}
