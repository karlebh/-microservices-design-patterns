# Compensating Transactions

## The problem

A database `ROLLBACK` works because nothing has left the database yet. In a distributed workflow each step **commits for real** as it goes: the card was charged, the seat was booked, the email was sent. When step 3 fails there is nothing to roll back — steps 1 and 2 already happened and other people may have seen them.

## The idea

Undo with **new forward actions** that cancel out the effect of earlier ones. A compensating transaction is the business-level opposite of a step:

| Step | Compensation |
| --- | --- |
| Charge the card | Issue a refund |
| Book the flight | Cancel the booking (maybe with a fee) |
| Reserve stock | Release the reservation |
| Send "order confirmed" email | Send "order cancelled" email |

Record each completed step together with its undo. On failure, run the undos in **reverse order**.

## In this folder

| File | Role |
| --- | --- |
| `CompensationScope.php` | Runs steps, remembers how to undo each one, and on demand compensates newest-first with retries; steps it can't undo are parked for a human |

## What to notice

- **Compensation is not rollback.** History is not erased: the ledger shows a charge *and* a refund. The world is returned to an *equivalent* state, not the identical one.
- **Compensations must not fail** — and since they still can, they are **retried**, so they must be **idempotent**. When retries run out, the step goes to a manual-intervention list instead of being forgotten. The rest still gets compensated.
- **Reverse order matters:** later steps often depend on earlier ones.
- **Some things can't be undone** (an email, a shipped parcel). Put those steps last — after the *pivot*, the last step that can fail — so you never need to undo them.
- Between a step and its compensation the intermediate state is **visible** to everyone else (no isolation).
- This is the building block that [Sagas](../Saga) are made of.

## Try it

```bash
php artisan test --filter=CompensatingTransactionsTest
```
