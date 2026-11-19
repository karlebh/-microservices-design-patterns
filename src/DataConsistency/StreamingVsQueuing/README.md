# Event Streaming vs Message Queuing

Both move messages between services, but they are different data structures with different promises.

## Message queue — a to-do list

A message is a **task**. It is delivered to **one** consumer, and once that consumer acknowledges it, the broker **deletes** it. Add consumers to share the work.

*RabbitMQ, SQS, ActiveMQ, Laravel queues.*

## Event stream — a log book

A message is a **fact** appended to an ordered, durable **log**. Reading doesn't remove anything. Each consumer group just remembers **how far it has read** (its offset), so many groups can read the same events independently, and any of them can rewind and read again.

*Kafka, Pulsar, Kinesis, Redis Streams.*

| | Queue | Stream |
| --- | --- | --- |
| After consumption | message is deleted | message stays (until retention) |
| Who gets a message | one consumer | every consumer group |
| Replay old messages | no | yes — move the offset back |
| New consumer added later | sees only new messages | can read history from the start |
| Ordering | best effort with competing consumers | strict within a partition |
| Broker tracks | state of every message | one offset per group |
| Good for | jobs, commands, work distribution | events, audit, rebuilding state, analytics |

## In this folder

| File | Role |
| --- | --- |
| `MessageQueue.php` | Destructive, competing consumption with ack / nack |
| `EventStream.php` | Append-only log with per-group offsets, seek/replay and retention |

## What to notice

- In the queue, a **nack** (or a crashed consumer) puts the message back for someone else — at-least-once. See [Delivery Semantics](../DeliverySemantics).
- In the stream, "consuming" is just advancing a number. Reprocessing after a bug fix is `seek(group, 0)`.
- Retention in a stream is by **time/size**, not by consumption: slow consumers can fall off the end.
- They are not competitors: a typical system uses a stream for events between services and a queue for background jobs inside one.

## Try it

```bash
php artisan test --filter=StreamingVsQueuingTest
```
