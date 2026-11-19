<?php

declare(strict_types=1);

namespace Patterns\Coordination\FencingTokens;

/**
 * Epoch numbers: fencing applied to leadership.
 *
 * Each election produces a leader with a higher epoch (Raft calls it a
 * term). Every command a leader sends carries its epoch. A follower keeps
 * the highest epoch it has seen and ignores commands from anything older —
 * which is how a leader that was partitioned away, and doesn't know it was
 * replaced, is prevented from doing damage when it reconnects.
 */
final class Follower
{
    private int $currentEpoch = 0;

    /** @var list<string> commands accepted, in order */
    public array $applied = [];

    /**
     * @return bool true if the command was accepted
     */
    public function receive(int $leaderEpoch, string $command): bool
    {
        if ($leaderEpoch < $this->currentEpoch) {
            return false; // from a deposed leader: the reply also tells it that it is out of date
        }

        $this->currentEpoch = $leaderEpoch;
        $this->applied[] = $command;

        return true;
    }

    public function epoch(): int
    {
        return $this->currentEpoch;
    }
}
