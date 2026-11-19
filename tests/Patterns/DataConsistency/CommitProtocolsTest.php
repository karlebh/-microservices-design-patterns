<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use Patterns\DataConsistency\CommitProtocols\CoordinatorCrashed;
use Patterns\DataConsistency\CommitProtocols\Participant;
use Patterns\DataConsistency\CommitProtocols\ParticipantState;
use Patterns\DataConsistency\CommitProtocols\ThreePhaseCommitCoordinator;
use Patterns\DataConsistency\CommitProtocols\TwoPhaseCommitCoordinator;
use PHPUnit\Framework\TestCase;

final class CommitProtocolsTest extends TestCase
{
    /** @return list<Participant> */
    private function participants(): array
    {
        return [new Participant('orders-db'), new Participant('payments-db'), new Participant('stock-db')];
    }

    /** @param list<Participant> $participants */
    private function states(array $participants): array
    {
        return array_map(fn (Participant $p) => $p->state, $participants);
    }

    // ---- Two-phase commit ---------------------------------------------------------------

    public function test_2pc_commits_everywhere_when_everyone_votes_yes(): void
    {
        $participants = $this->participants();

        $this->assertTrue((new TwoPhaseCommitCoordinator)->execute('tx-1', $participants));
        $this->assertSame(array_fill(0, 3, ParticipantState::Committed), $this->states($participants));
    }

    public function test_2pc_aborts_everywhere_when_one_participant_votes_no(): void
    {
        $participants = $this->participants();
        $participants[1]->votesYes = false;

        $this->assertFalse((new TwoPhaseCommitCoordinator)->execute('tx-1', $participants));
        $this->assertSame(
            array_fill(0, 3, ParticipantState::Aborted),
            $this->states($participants),
            'Including the one that had already voted yes.'
        );
    }

    public function test_2pc_treats_silence_as_a_no_vote(): void
    {
        $participants = $this->participants();
        $participants[2]->unreachable = true;

        $this->assertFalse((new TwoPhaseCommitCoordinator)->execute('tx-1', $participants));
        $this->assertSame(ParticipantState::Aborted, $participants[0]->state);
        $this->assertSame(ParticipantState::Aborted, $participants[1]->state);
    }

    public function test_2pc_blocks_when_the_coordinator_dies_after_the_votes(): void
    {
        $participants = $this->participants();
        $coordinator = new TwoPhaseCommitCoordinator;
        $coordinator->crashBeforeDecision = true;

        try {
            $coordinator->execute('tx-1', $participants);
        } catch (CoordinatorCrashed) {
            // the coordinator is gone
        }

        // Everyone promised to commit, nobody knows the outcome, and every
        // participant keeps its locks. They are stuck until the coordinator returns.
        foreach ($participants as $participant) {
            $this->assertTrue($participant->isInDoubt());
            $this->assertTrue($participant->holdsLocks());
        }

        // The coordinator comes back, finds no decision in its log, and aborts.
        $this->assertFalse($coordinator->recover('tx-1', $participants));
        $this->assertSame(array_fill(0, 3, ParticipantState::Aborted), $this->states($participants));
    }

    public function test_2pc_recovery_finishes_a_commit_that_was_interrupted_half_way(): void
    {
        $participants = $this->participants();
        $coordinator = new TwoPhaseCommitCoordinator;
        $coordinator->crashAfterNotifying = 1;

        try {
            $coordinator->execute('tx-1', $participants);
        } catch (CoordinatorCrashed) {
            // died after telling only the first participant
        }

        $this->assertSame(
            [ParticipantState::Committed, ParticipantState::Prepared, ParticipantState::Prepared],
            $this->states($participants),
            'A dangerous in-between: one committed, two still in doubt.'
        );

        // The decision was logged BEFORE phase 2 began, so recovery can only repeat "commit".
        $this->assertTrue($coordinator->recover('tx-1', $participants));
        $this->assertSame(array_fill(0, 3, ParticipantState::Committed), $this->states($participants));
    }

    public function test_2pc_delivers_the_decision_to_a_participant_that_was_down_during_phase_two(): void
    {
        $participants = $this->participants();
        $coordinator = new TwoPhaseCommitCoordinator;
        $coordinator->crashAfterNotifying = 2;

        try {
            $coordinator->execute('tx-1', $participants);
        } catch (CoordinatorCrashed) {
            // stock-db voted yes but never heard the decision
        }

        // By the time the coordinator is back, stock-db itself is down.
        $participants[2]->unreachable = true;

        $this->assertTrue($coordinator->recover('tx-1', $participants), 'The decision is still "commit".');
        $this->assertTrue($participants[2]->isInDoubt(), 'It cannot be told yet, so it keeps waiting.');

        // Recovery is simply repeated until the participant answers.
        $participants[2]->unreachable = false;
        $coordinator->recover('tx-1', $participants);

        $this->assertSame(ParticipantState::Committed, $participants[2]->state);
    }

    // ---- Three-phase commit -------------------------------------------------------------

    public function test_3pc_commits_when_everyone_votes_yes_and_aborts_otherwise(): void
    {
        $happy = $this->participants();
        $this->assertTrue((new ThreePhaseCommitCoordinator)->execute($happy));
        $this->assertSame(array_fill(0, 3, ParticipantState::Committed), $this->states($happy));

        $unhappy = $this->participants();
        $unhappy[0]->votesYes = false;
        $this->assertFalse((new ThreePhaseCommitCoordinator)->execute($unhappy));
        $this->assertSame(array_fill(0, 3, ParticipantState::Aborted), $this->states($unhappy));
    }

    public function test_3pc_participants_abort_on_their_own_if_the_coordinator_dies_before_pre_commit(): void
    {
        $participants = $this->participants();
        $coordinator = new ThreePhaseCommitCoordinator;
        $coordinator->crashAfterPhase = 'can_commit';

        try {
            $coordinator->execute($participants);
        } catch (CoordinatorCrashed) {
            // gone, and it is NOT coming back
        }

        foreach ($participants as $participant) {
            $participant->onCoordinatorTimeout();
        }

        $this->assertSame(
            array_fill(0, 3, ParticipantState::Aborted),
            $this->states($participants),
            'No one is left waiting: this is what 3PC adds over 2PC.'
        );
    }

    public function test_3pc_participants_commit_on_their_own_if_the_coordinator_dies_after_pre_commit(): void
    {
        $participants = $this->participants();
        $coordinator = new ThreePhaseCommitCoordinator;
        $coordinator->crashAfterPhase = 'pre_commit';

        try {
            $coordinator->execute($participants);
        } catch (CoordinatorCrashed) {
            // gone
        }

        foreach ($participants as $participant) {
            $participant->onCoordinatorTimeout();
        }

        $this->assertSame(array_fill(0, 3, ParticipantState::Committed), $this->states($participants));
    }

    public function test_3pc_can_split_the_decision_under_a_network_partition(): void
    {
        $participants = $this->participants();
        $coordinator = new ThreePhaseCommitCoordinator;
        $coordinator->crashAfterPreCommitting = 1; // only orders-db hears "pre-commit"

        try {
            $coordinator->execute($participants);
        } catch (CoordinatorCrashed) {
            // gone
        }

        // A partition keeps the participants from comparing notes, so each
        // applies the timeout rule to its own state.
        foreach ($participants as $participant) {
            $participant->onCoordinatorTimeout();
        }

        $this->assertSame(
            [ParticipantState::Committed, ParticipantState::Aborted, ParticipantState::Aborted],
            $this->states($participants),
            'Atomicity is violated: 3PC trades safety for liveness when the network partitions.'
        );
    }
}
