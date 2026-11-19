# Saga Pattern (Choreography & Orchestration)

## The problem

Placing an order touches three services, each with its own database: reserve stock (inventory), take the money (payment), create the order (orders). There is no database transaction that spans all three. If payment fails after stock was reserved, something has to un-reserve the stock.

## The idea

A **saga** is a sequence of *local* transactions. Each step commits on its own. If a later step fails, the saga runs **compensating actions** for the steps that already committed, in reverse order. You get "all or nothing" *eventually*, without distributed locks.

There are two ways to coordinate the sequence:

| | Orchestration | Choreography |
| --- | --- | --- |
| Who decides the next step? | A central orchestrator | Nobody: each service reacts to events |
| Where is the workflow visible? | In one place | Spread across the subscribers |
| Coupling | Services know nothing about each other; the orchestrator knows all | Services know the events they react to |
| Good for | Long or branching workflows, anything you must monitor | Short, simple flows with few participants |

## In this folder

| File | Role |
| --- | --- |
| `Orchestration/SagaOrchestrator.php` | Runs steps in order; on failure, compensates completed steps newest-first |
| `Orchestration/SagaStep.php`, `SagaResult.php` | A step = action + compensation; the outcome and an execution log |
| `Choreography/EventBus.php` | In-memory publish/subscribe |
| `Choreography/OrderService.php`, `InventoryService.php`, `PaymentService.php` | Three services that only communicate through events, including failure events that trigger compensation |

## What to notice

- **No isolation.** Between steps, other requests can see the half-finished state (stock reserved, order not yet confirmed). Design states for it (`pending`, `confirmed`, `cancelled`).
- **Compensation is a new action, not a rollback** — refund instead of "un-charge". See [Compensating Transactions](../CompensatingTransactions).
- Steps and compensations must be **idempotent**, because messages get redelivered.
- In choreography, look at how `PaymentService` never mentions `InventoryService`: the failure event alone makes the right service clean up.
- A real orchestrator **persists its progress** after every step so it can resume after a crash.

## Try it

```bash
php artisan test --filter=SagaTest
```
