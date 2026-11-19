# Outbox + CDC Integration

## The problem

The [Transactional Outbox](../TransactionalOutbox) makes "save the order" and "record the event" atomic. But its relay **polls** the outbox table:

- every poll is a query, even when there is nothing to send
- latency = the polling interval
- it needs a `published_at` flag, so every event costs an extra `UPDATE`
- several relays need row locking (`SKIP LOCKED`) to avoid stepping on each other

## The idea

Keep the outbox table, drop the polling. A [CDC](../../DataArchitecture/ChangeDataCapture) connector already reads the database's transaction log, and an insert into the outbox table is just another entry in that log. So let the connector publish it.

```
BEGIN
  insert into orders ...
  insert into outbox ...     ──► transaction log ──► CDC connector ──► event router ──► broker topic
  delete from outbox ...                                (only INSERTs on `outbox` matter)
COMMIT
```

The outbox row is no longer something to query. It is a way of **writing a message into the transaction log**. That is why it can be deleted in the same transaction that inserts it: the log keeps the insert, the table stays empty.

## In this folder

| File | Role |
| --- | --- |
| `OrderService.php` | Writes the order and its outbox row atomically, and deletes the outbox row straight away |
| `OutboxEventRouter.php` | The connector's transform (cf. Debezium's *Outbox Event Router*): keeps only outbox inserts, picks the topic from `aggregate_type`, uses `aggregate_id` as the partition key |

The database, transaction log and connector come from [`DataArchitecture/ChangeDataCapture`](../../DataArchitecture/ChangeDataCapture).

## What to notice

- **No polling, no flag, no locking.** The connector's only state is its log offset.
- **Order is commit order**, straight from the log.
- **Why an outbox at all, instead of capturing the `orders` table?** Because the outbox is a deliberate, stable event contract. Capturing business tables publishes your internal schema.
- Still **at-least-once**: a connector that crashes after publishing but before saving its offset publishes again. Each event has a stable id for deduplication.
- Cost: you now operate a CDC pipeline. For low volume, the polling relay is simpler.

## Try it

```bash
php artisan test --filter=OutboxCdcTest
```
