# Transactional Outbox

## The problem

A service must do two things when an order is placed: **save the order** and **publish `order.placed`** to a broker. Those are two different systems, so there is no shared transaction (the "dual write" problem):

- save, then publish fails → the order exists but nobody ever hears about it
- publish, then save fails → everyone reacts to an order that does not exist

## The idea

Don't talk to the broker inside the request at all. Write the event into an **outbox table in the same database transaction** as the business row. A separate **relay** process reads the outbox and publishes. The database's atomicity now covers both writes.

```
request ──► BEGIN
              insert into outbox_orders   ┐ one local
              insert into outbox_messages ┘ transaction
            COMMIT
relay   ──► select unpublished ──► publish to broker ──► mark published
```

## In this folder

| File | Role |
| --- | --- |
| `PlaceOrder.php` | The business action: order row + outbox row in one transaction |
| `OutboxRelay.php` | Polls the outbox, publishes in insertion order, marks rows published |
| `MessagePublisher.php` | Port to the broker (`LogPublisher` for dev, `InMemoryPublisher` for tests) |
| `Console/RelayOutboxCommand.php` | `php artisan outbox:relay` — the relay as a process |
| `Migrations/` | `outbox_orders` and `outbox_messages` tables |

## What to notice

- **Delivery is at-least-once, never exactly-once.** If the relay publishes and crashes before marking the row, it publishes again on restart. Every message carries a `message_id` so consumers can dedupe — see [Idempotency](../Idempotency).
- **Ordering:** the relay stops a batch at the first failure so a later message can't overtake an earlier one.
- **Several relays:** on Postgres/MySQL the relay selects with `FOR UPDATE SKIP LOCKED` so two relays never grab the same rows.
- Polling adds latency and load. The alternative is tailing the database log instead — see [Outbox + CDC](../OutboxCdc).

## Try it

```bash
php artisan test --filter=TransactionalOutboxTest
php artisan migrate && php artisan outbox:relay
```
