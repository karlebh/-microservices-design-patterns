# Event Sourcing

## The problem

A normal table stores the **current state** only: `balance = 70`. How it got there is gone. You can't answer "what was the balance last Tuesday?", you can't prove what happened, and an `UPDATE` with a bug silently destroys information.

## The idea

Store **what happened** instead of what is. The source of truth is an append-only list of events per entity (a *stream*):

```
account-1:  AccountOpened → MoneyDeposited(100) → MoneyWithdrawn(30)
```

Current state is never stored; it is **computed by replaying** the events (`0 + 100 - 30 = 70`). Events are facts in the past tense and are never updated or deleted.

## In this folder

| File | Role |
| --- | --- |
| `DomainEvent.php` | A fact: type + payload |
| `EventStore.php` | Append-only streams, with an expected-version check on append |
| `BankAccount.php` | The aggregate. Commands validate against current state, then *record events*; state changes only by applying events |
| `BankAccountRepository.php` | Load = replay (optionally from a snapshot). Save = append the new events |
| `BalanceProjection.php` | A read model built by folding over all events |
| `ConcurrencyException.php` | Someone else appended to the stream first |

## What to notice

- **Two-step commands:** decide (check invariants, e.g. no overdraft) → record an event → apply it. Replaying history calls the same `apply`, which is why state is always reproducible.
- **Optimistic concurrency:** `append(stream, expectedVersion, events)`. If the stream moved on since you loaded it, the append is refused and you retry. In SQL this is a unique index on `(stream_id, version)`.
- **Snapshots** are a cache, not a source of truth: "state at version N", so loading replays only the events after N. Delete them and nothing is lost.
- **Time travel** is free: replay the first N events to get the state as it was.
- **Projections** are disposable. Need a new report? Write a new projection and replay from event zero.
- **Costs:** events are forever, so changing their shape needs versioning/upcasting; queries need projections (hence [CQRS](../Cqrs)); and read models are eventually consistent.

## Try it

```bash
php artisan test --filter=EventSourcingTest
```
