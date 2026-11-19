<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\CommitProtocols;

use RuntimeException;

/**
 * One resource taking part in a distributed transaction (a database, a
 * queue, another service).
 */
final class Participant
{
    public ParticipantState $state = ParticipantState::Init;

    /** Set to false to make this participant vote "no" (e.g. a constraint would be violated). */
    public bool $votesYes = true;

    /** Simulates the node being down: every message to it fails. */
    public bool $unreachable = false;

    public function __construct(public readonly string $name) {}

    /** Phase 1 — "prepare" in 2PC, "canCommit?" in 3PC. Returns the vote. */
    public function prepare(): bool
    {
        $this->assertReachable();

        if (! $this->votesYes) {
            // A "no" voter knows the outcome already and may abort on its own.
            $this->state = ParticipantState::Aborted;

            return false;
        }

        // Voting yes is a promise: whatever happens next, a commit must succeed.
        $this->state = ParticipantState::Prepared;

        return true;
    }

    /** 3PC phase 2 — "everyone voted yes; get ready". */
    public function preCommit(): void
    {
        $this->assertReachable();

        if ($this->state === ParticipantState::Prepared) {
            $this->state = ParticipantState::PreCommitted;
        }
    }

    /** Idempotent: the coordinator may repeat the decision during recovery. */
    public function commit(): void
    {
        $this->assertReachable();

        $this->state = ParticipantState::Committed;
    }

    public function abort(): void
    {
        $this->assertReachable();

        if ($this->state !== ParticipantState::Committed) {
            $this->state = ParticipantState::Aborted;
        }
    }

    /**
     * 2PC's blocking problem in one method: a prepared participant that lost
     * its coordinator can neither commit nor abort safely.
     */
    public function isInDoubt(): bool
    {
        return $this->state === ParticipantState::Prepared;
    }

    /** Locks are held from the yes-vote until the final decision arrives. */
    public function holdsLocks(): bool
    {
        return in_array($this->state, [ParticipantState::Prepared, ParticipantState::PreCommitted], true);
    }

    /**
     * 3PC only: what a participant does by itself when the coordinator goes silent.
     *
     * The extra phase is what makes this safe (when nodes crash but the
     * network doesn't partition): the participant's own state reveals the
     * group's decision.
     */
    public function onCoordinatorTimeout(): void
    {
        match ($this->state) {
            // Pre-commit never reached us, so no one has committed yet.
            ParticipantState::Prepared => $this->state = ParticipantState::Aborted,
            // We know all votes were yes, so committing is the only possible outcome.
            ParticipantState::PreCommitted => $this->state = ParticipantState::Committed,
            default => null,
        };
    }

    private function assertReachable(): void
    {
        if ($this->unreachable) {
            throw new RuntimeException("Participant [{$this->name}] did not answer.");
        }
    }
}
