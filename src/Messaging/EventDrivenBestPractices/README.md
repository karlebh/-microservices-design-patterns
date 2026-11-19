# Event-Driven Architecture Best Practices

Events are easy to publish and hard to live with for years. The practices below are the ones that are cheap on day one and very expensive to retrofit. Three of them are implemented here; the rest have their own folders.

## 1. Wrap every event in a standard envelope

Separate **metadata** (the same for every event) from **data** (specific to the type). The envelope answers questions you will otherwise be unable to answer in production:

| Field | Answers |
| --- | --- |
| `id` | Have I seen this exact event before? (deduplication) |
| `type`, `version` | What is it, and which shape does `data` have? |
| `source` | Who published it? |
| `occurredAt` | When did it *happen* (not when was it delivered)? |
| `correlationId` | Which business flow is this part of? — identical on every event of the flow |
| `causationId` | What directly caused it? — the id of the parent event |

`correlationId` gives you the whole story of one checkout; `causationId` gives you the tree. → `EventEnvelope.php`

## 2. Version the schema, and upcast old events

Events live forever (in logs, in event stores), so consumers will meet old shapes. Don't scatter `if (version === 1)` through handlers. Convert old events to the newest shape **at the edge**, one step at a time (v1→v2→v3), so business code only knows the latest version. → `Upcaster.php`

## 3. Guard compatibility with a schema registry

Producers and consumers deploy independently, so every change must be compatible **in both directions**: old consumers must read new events, and new consumers must read old events. That leaves one safe kind of change — **adding an optional field**. Reject the rest before it ships. → `SchemaRegistry.php`

## The rest of the list

| Practice | See |
| --- | --- |
| Name events as facts in the past tense (`order.placed`), never as commands | – |
| Publish atomically with the state change | [Transactional Outbox](../../DataConsistency/TransactionalOutbox) |
| Consumers must be idempotent (delivery is at-least-once) | [Idempotency](../../DataConsistency/Idempotency) |
| Decide ordering needs per key, and partition by that key | [Message Ordering](../MessageOrdering) |
| Thin vs fat events is a deliberate choice | [Publish-Subscribe](../../DataConsistency/PublishSubscribe) |
| Park messages that can't be processed instead of blocking | [Dead Letter Queues](../../Resilience/DeadLetterQueue) |
| Be a tolerant reader: ignore fields you don't know | `Upcaster` test |
| Never put secrets or unnecessary personal data in events: they are copied everywhere and kept forever | [Data Protection](../../Security/DataProtection) |

## Try it

```bash
php artisan test --filter=EventDrivenBestPracticesTest
```
