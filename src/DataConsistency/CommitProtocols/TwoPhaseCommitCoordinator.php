<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\CommitProtocols;

use RuntimeException;

/**
 * Two-phase commit.
 *
 *   Phase 1 (voting):     ask every participant to prepare and collect votes
 *   --- write the decision to the coordinator's durable log: the commit point ---
 *   Phase 2 (completion): tell every participant the decision
 *
 * Once the decision is logged it never changes; recovery just repeats it
 * until every participant has heard it.
 */
final class TwoPhaseCommitCoordinator
{
    /** @var array<string, string> transaction id => 'commit' | 'abort'. Survives a crash. */
    private array $decisionLog = [];

    /** Test hook: die after collecting votes but before logging a decision. */
    public bool $crashBeforeDecision = false;

    /** Test hook: die during phase 2 after informing this many participants. */
    public ?int $crashAfterNotifying = null;

    /**
     * @param  list<Participant>  $participants
     * @return bool true if the transaction committed
     */
    public function execute(string $transactionId, array $participants): bool
    {
        $allVotedYes = $this->collectVotes($participants);

        if ($this->crashBeforeDecision) {
            throw new CoordinatorCrashed('Crashed after the votes, before deciding.');
        }

        // The commit point. Before this line: the outcome is open.
        // After it: the outcome is fixed, even if we crash right now.
        $this->decisionLog[$transactionId] = $allVotedYes ? 'commit' : 'abort';

        $this->announceDecision($transactionId, $participants);

        return $allVotedYes;
    }

    /**
     * The coordinator restarts and finishes an interrupted transaction.
     *
     * @param  list<Participant>  $participants
     */
    public function recover(string $transactionId, array $participants): bool
    {
        $this->crashBeforeDecision = false;
        $this->crashAfterNotifying = null;

        // Nothing logged means we died before the commit point, so no
        // participant can have been told to commit: aborting is safe.
        $this->decisionLog[$transactionId] ??= 'abort';

        $this->announceDecision($transactionId, $participants);

        return $this->decisionLog[$transactionId] === 'commit';
    }

    /** @param list<Participant> $participants */
    private function collectVotes(array $participants): bool
    {
        foreach ($participants as $participant) {
            try {
                if (! $participant->prepare()) {
                    return false;
                }
            } catch (RuntimeException) {
                return false; // no answer counts as a "no"
            }
        }

        return true;
    }

    /** @param list<Participant> $participants */
    private function announceDecision(string $transactionId, array $participants): void
    {
        foreach ($participants as $index => $participant) {
            if ($this->crashAfterNotifying === $index) {
                throw new CoordinatorCrashed('Crashed while announcing the decision.');
            }

            try {
                $this->decisionLog[$transactionId] === 'commit'
                    ? $participant->commit()
                    : $participant->abort();
            } catch (RuntimeException) {
                // Unreachable right now. It stays in doubt until a later
                // recover() reaches it; the decision itself doesn't change.
            }
        }
    }
}
