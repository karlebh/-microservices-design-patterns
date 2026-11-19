# At-Least-Once, At-Most-Once, and Exactly-Once Delivery

## The problem

A consumer does two things with every message: **process it** (a side effect: update a balance) and **acknowledge it** (move its offset so the broker doesn't send it again). It can crash between the two. Which one goes first decides what a crash costs you.

## The three guarantees

| Guarantee | Order of operations | A crash in between causes | Use when |
| --- | --- | --- | --- |
| **At-most-once** | ack, *then* process | the message is **lost** | losing one is fine (metrics, logs) |
| **At-least-once** | process, *then* ack | the message is **processed twice** | the default; make the handler idempotent |
| **Exactly-once** | process **and** ack atomically | nothing | the effect and the offset can live in the same transaction |

"Exactly-once **delivery**" over an unreliable network is impossible: the sender can never be sure an unacknowledged message arrived. What systems actually offer is exactly-once **processing** (a.k.a. *effectively once*): messages may be delivered more than once, but their effect is applied once. There are two ways to get it:

1. **Atomic commit** — store the consumer's offset in the same transaction as the result (shown here; Kafka transactions do this for Kafka-to-Kafka pipelines).
2. **Idempotent processing** — at-least-once delivery + deduplication by message id (see [Idempotency](../Idempotency)).

## In this folder

| File | Role |
| --- | --- |
| `MessageLog.php` | A source of messages with offsets, tracking each consumer's committed position |
| `Ledger.php` | The side effect: a balance that is *not* idempotent (applying twice is wrong) |
| `Consumer.php` | One consumer, three strategies — the only difference is the order of two lines |
| `DeliveryGuarantee.php` | The three modes |
| `ProcessCrashed.php` | Thrown to simulate the process dying between the two steps |

## What to notice

- Open `Consumer.php` and compare the three methods: same two operations, different order.
- Exactly-once needs the offset to be stored **with the data**, so the consumer resumes from the ledger's position, not the broker's.
- The guarantee is **end to end**: a producer that retries can also duplicate (fixed by idempotent producers / the [Outbox](../TransactionalOutbox)).

## Try it

```bash
php artisan test --filter=DeliverySemanticsTest
```
