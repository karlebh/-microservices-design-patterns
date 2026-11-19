<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\CommitProtocols;

use RuntimeException;

/**
 * Three-phase commit.
 *
 *   Phase 1 (canCommit?): collect votes, exactly like 2PC's prepare
 *   Phase 2 (preCommit):  tell everyone "all votes were yes"
 *   Phase 3 (doCommit):   commit
 *
 * The middle phase removes 2PC's uncertainty: a participant is never in a
 * state where "some committed" and "some aborted" are both still possible.
 * So when the coordinator disappears, participants can finish on their own
 * (see Participant::onCoordinatorTimeout).
 */
final class ThreePhaseCommitCoordinator
{
    /** Test hook: die right after this phase completes ('can_commit' or 'pre_commit'). */
    public ?string $crashAfterPhase = null;

    /** Test hook: die in the middle of phase 2, after pre-committing this many participants. */
    public ?int $crashAfterPreCommitting = null;

    /**
     * @param  list<Participant>  $participants
     * @return bool true if the transaction committed
     */
    public function execute(array $participants): bool
    {
        // ---- Phase 1: canCommit?
        foreach ($participants as $participant) {
            if (! $this->votesYes($participant)) {
                foreach ($participants as $other) {
                    if (! $other->unreachable) {
                        $other->abort();
                    }
                }

                return false;
            }
        }

        if ($this->crashAfterPhase === 'can_commit') {
            throw new CoordinatorCrashed('Crashed after phase 1.');
        }

        // ---- Phase 2: preCommit
        foreach ($participants as $index => $participant) {
            if ($this->crashAfterPreCommitting === $index) {
                throw new CoordinatorCrashed('Crashed during phase 2.');
            }

            $participant->preCommit();
        }

        if ($this->crashAfterPhase === 'pre_commit') {
            throw new CoordinatorCrashed('Crashed after phase 2.');
        }

        // ---- Phase 3: doCommit
        foreach ($participants as $participant) {
            $participant->commit();
        }

        return true;
    }

    private function votesYes(Participant $participant): bool
    {
        try {
            return $participant->prepare();
        } catch (RuntimeException) {
            return false;
        }
    }
}
