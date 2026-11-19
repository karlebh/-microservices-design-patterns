<?php

declare(strict_types=1);

namespace Patterns\Coordination\Gossip;

use Random\Randomizer;

/**
 * Drives gossip rounds between nodes.
 *
 * In one round every live node increments its heartbeat, picks `fanout`
 * peers at random from the members it knows about, and does a push-pull
 * exchange with each: both sides end up with the union of what they knew.
 */
final class GossipCluster
{
    /** @var array<string, GossipNode> */
    private array $nodes = [];

    private int $round = 0;

    /** Total peer-to-peer exchanges so far: the cost of dissemination. */
    public int $exchanges = 0;

    /**
     * @param  int  $fanout  peers each node contacts per round
     * @param  float  $messageLoss  probability that an exchange fails (0.3 = 30% of them are lost)
     * @param  int  $failAfterRounds  rounds without heartbeat progress before a member is considered failed
     */
    public function __construct(
        private readonly Randomizer $random,
        private readonly int $fanout = 1,
        private readonly float $messageLoss = 0.0,
        private readonly int $failAfterRounds = 12,
    ) {}

    /** Create a cluster of fully acquainted nodes named node-1 ... node-N. */
    public function bootstrap(int $size): void
    {
        for ($i = 1; $i <= $size; $i++) {
            $this->nodes["node-{$i}"] = new GossipNode("node-{$i}");
        }

        foreach ($this->nodes as $node) {
            foreach (array_keys($this->nodes) as $id) {
                $node->members[$id] ??= ['heartbeat' => 0, 'lastChangedRound' => 0];
            }
        }
    }

    /** A new node joins knowing just one existing member (a seed). */
    public function join(string $id, string $seed): void
    {
        $newcomer = new GossipNode($id);
        $newcomer->members[$seed] = ['heartbeat' => 0, 'lastChangedRound' => $this->round];

        $this->nodes[$id] = $newcomer;
    }

    public function crash(string $id): void
    {
        $this->nodes[$id]->crashed = true;
    }

    public function node(string $id): GossipNode
    {
        return $this->nodes[$id];
    }

    public function round(): void
    {
        $this->round++;

        foreach ($this->nodes as $node) {
            if ($node->crashed) {
                continue;
            }

            $node->beat($this->round);

            $peers = array_values(array_diff(array_keys($node->members), [$node->id]));

            if ($peers === []) {
                continue;
            }

            for ($i = 0; $i < $this->fanout; $i++) {
                $peer = $this->nodes[$peers[$this->random->getInt(0, count($peers) - 1)]] ?? null;

                $this->exchanges++;

                // A crashed peer doesn't answer; a lossy network sometimes eats the message.
                if ($peer === null || $peer->crashed || $this->random->nextFloat() < $this->messageLoss) {
                    continue;
                }

                // Push-pull: both sides learn what the other knows.
                $peer->receive($node, $this->round);
                $node->receive($peer, $this->round);
            }
        }
    }

    public function rounds(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->round();
        }
    }

    /**
     * Run rounds until every live node satisfies the condition.
     *
     * @param  callable(GossipNode): bool  $condition
     * @return int how many rounds it took
     */
    public function roundsUntilAll(callable $condition, int $limit = 500): int
    {
        for ($rounds = 0; $rounds < $limit; $rounds++) {
            $pending = array_filter($this->nodes, fn (GossipNode $node) => ! $node->crashed && ! $condition($node));

            if ($pending === []) {
                return $rounds;
            }

            $this->round();
        }

        return $limit;
    }

    /** How many live nodes have the given key. */
    public function nodesKnowing(string $key): int
    {
        return count(array_filter($this->nodes, fn (GossipNode $node) => ! $node->crashed && isset($node->data[$key])));
    }

    /**
     * What a node currently believes the live membership to be.
     *
     * @return list<string>
     */
    public function viewOf(string $id): array
    {
        return $this->nodes[$id]->aliveMembers($this->round, $this->failAfterRounds);
    }

    public function currentRound(): int
    {
        return $this->round;
    }
}
