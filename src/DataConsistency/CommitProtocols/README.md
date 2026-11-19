# Two-Phase Commit (2PC) / Three-Phase Commit (3PC)

## The problem

One transaction spans several databases or services, and it must be **atomic**: either every participant commits or every participant aborts. Each participant can fail independently, and so can whoever is coordinating.

## Two-phase commit

A coordinator runs two rounds:

1. **Prepare (voting).** "Can you commit?" Each participant does the work, makes it durable, keeps its locks, and votes yes or no. A yes is a **promise**: it can no longer fail or back out on its own.
2. **Commit (completion).** All yes → the coordinator **logs "commit"** (the point of no return) and tells everyone to commit. Any no or silence → abort.

```
coordinator      A        B
    │── prepare ─►│        │
    │── prepare ──────────►│
    │◄── yes ─────│        │
    │◄── yes ──────────────│
    │  log COMMIT          │
    │── commit ──►│        │
    │── commit ───────────►│
```

**The flaw: 2PC blocks.** If the coordinator dies after the votes, a participant that voted yes is *in doubt*: it can't commit (maybe someone voted no) and can't abort (maybe the others committed). It sits there holding locks until the coordinator comes back.

## Three-phase commit

3PC inserts a **pre-commit** round between voting and committing, so a participant's state tells it what the group decided:

- timed out while only *prepared* → nobody was told to commit yet → **abort**
- timed out while *pre-committed* → everybody voted yes → **commit**

No participant waits forever. But it assumes a failure is a crash, never a network partition. If a partition splits pre-committed participants from prepared ones, they decide differently and atomicity is broken.

## In this folder

| File | Role |
| --- | --- |
| `Participant.php`, `ParticipantState.php` | A resource manager and its state machine, including the 3PC timeout rule |
| `TwoPhaseCommitCoordinator.php` | 2PC with a durable decision log and crash recovery |
| `ThreePhaseCommitCoordinator.php` | 3PC |
| `CoordinatorCrashed.php` | Thrown by the test hooks that kill the coordinator mid-protocol |

## What to notice

- 2PC is **safe but not live**: it never produces a wrong answer, but it can stop making progress. 3PC is **live but not safe** under partitions.
- Real systems that need both use consensus (Paxos/Raft) to replicate the coordinator's decision — see [Consensus](../../Coordination/Consensus).
- Across microservices, 2PC is usually avoided altogether (locks held across network calls, availability = product of all participants). Use a [Saga](../Saga) instead and accept eventual consistency.

## Try it

```bash
php artisan test --filter=CommitProtocolsTest
```
