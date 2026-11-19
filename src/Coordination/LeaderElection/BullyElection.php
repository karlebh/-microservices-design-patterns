<?php

declare(strict_types=1);

namespace Patterns\Coordination\LeaderElection;

/**
 * The Bully algorithm: the live node with the highest id becomes leader.
 *
 * Three message types:
 *   ELECTION     "I think the leader is dead. Anyone bigger than me out there?"
 *   OK           "Yes, me. Stand down, I'll take it from here."
 *   COORDINATOR  "I am the leader now."
 */
final class BullyElection
{
    /** @var array<int, bool> node id => alive? */
    private array $alive = [];

    private ?int $leader = null;

    /** Messages exchanged in total: Bully is chatty. */
    public int $messages = 0;

    /** @var list<string> what happened, in order */
    public array $trace = [];

    /** @param list<int> $nodeIds */
    public function __construct(array $nodeIds)
    {
        foreach ($nodeIds as $id) {
            $this->alive[$id] = true;
        }

        ksort($this->alive);
        $this->leader = max($nodeIds);
    }

    public function crash(int $id): void
    {
        $this->alive[$id] = false;
    }

    /** A node that comes back immediately holds an election, and wins it if it has the highest id. */
    public function recover(int $id): int
    {
        $this->alive[$id] = true;

        return $this->startElection($id);
    }

    public function leader(): ?int
    {
        return $this->leader;
    }

    /**
     * $initiator has noticed that the leader is not responding.
     *
     * @return int the id of the newly elected leader
     */
    public function startElection(int $initiator): int
    {
        $waiting = [$initiator];
        $started = [];

        while ($waiting !== []) {
            $node = array_shift($waiting);

            if (isset($started[$node])) {
                continue;
            }

            $started[$node] = true;
            $this->trace[] = "{$node} sends ELECTION to all higher nodes";

            $someoneBiggerAnswered = false;

            foreach (array_keys($this->alive) as $other) {
                if ($other <= $node) {
                    continue;
                }

                $this->messages++; // ELECTION

                if ($this->alive[$other]) {
                    $this->messages++; // OK
                    $someoneBiggerAnswered = true;
                    $waiting[] = $other; // the bigger node now runs its own election
                }
            }

            if (! $someoneBiggerAnswered) {
                // Nobody outranks this node: it wins and tells everyone else.
                $this->leader = $node;
                $this->messages += count(array_filter($this->alive)) - 1; // COORDINATOR
                $this->trace[] = "{$node} hears no answer and announces itself as COORDINATOR";
            } else {
                $this->trace[] = "{$node} got an OK and stands down";
            }
        }

        return $this->leader;
    }
}
