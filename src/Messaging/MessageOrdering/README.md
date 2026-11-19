# Message Ordering Guarantees (per-key, global)

## The problem

`deposit 100` then `withdraw 100` works. `withdraw 100` then `deposit 100` bounces. Messages often only make sense in the order they were produced, yet a distributed system reorders them all the time: parallel consumers, retries, multiple partitions, multiple producers.

Ordering is not free: **the stronger the ordering, the less you can parallelise.**

## The levels

| Guarantee | How it is achieved | Cost |
| --- | --- | --- |
| **None** | Competing consumers on one queue | – (maximum parallelism) |
| **Per key** | Partition by key; one consumer per partition | Parallelism limited to #partitions; hot keys |
| **Global (total)** | One partition, one consumer | No parallelism at all: throughput of a single consumer |

**Per-key ordering is almost always what you actually need.** "All events of account 42 in order" matters; whether account 42's deposit comes before or after account 77's is meaningless.

## When the transport can't guarantee it: resequence

If messages can still arrive out of order (retries, several producers, an unordered transport), the *consumer* can restore order — provided the producer stamps each message with a **sequence number**:

- arrived the one I expected → deliver, then deliver any buffered successors
- arrived a later one → buffer it, there is a gap
- arrived an earlier one → duplicate, drop it

## In this folder

| File | Role |
| --- | --- |
| `PartitionedTopic.php` | Key-hashed partitions: shows what is and isn't ordered once there is more than one |
| `SequenceStamper.php` | Producer side: a monotonically increasing sequence number per key |
| `Resequencer.php` | Consumer side: buffers out-of-order messages, releases them in order, drops duplicates |

## What to notice

- With several partitions, the *interleaving across keys* differs from publish order, but each key's own sequence is intact.
- A gap in the resequencer blocks **only that key**. In production you also need a policy for a gap that never fills (timeout → skip, or park and alert).
- Ordering is end-to-end: an in-order transport doesn't help if the consumer then fans messages out to a thread pool.
- Retries reorder too: if message 1 fails and is retried after message 2 succeeded, order is lost unless the consumer blocks the partition while retrying.

## Try it

```bash
php artisan test --filter=MessageOrderingTest
```
