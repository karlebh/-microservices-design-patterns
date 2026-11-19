# Competing Consumers & Partitioned Processing

Two ways to put more than one consumer on a stream of messages. They scale differently and make opposite promises about order.

## Competing consumers

Several workers pull from **one queue**. Whoever is free takes the next message.

```
              ┌─► worker A
[ m4 m3 m2 m1 ]─► worker B     any message → any worker
              └─► worker C
```

- ✔ Scale by adding workers; load balances itself; a slow worker just takes fewer messages.
- ✔ A dead worker's message reappears after the **visibility timeout** and someone else takes it.
- ✘ **No ordering.** m1 and m2 can be processed at the same time by different workers, and m2 may finish first.

## Partitioned processing

Messages are split by **key** into partitions; each partition has **one** owner.

```
key=A ─► partition 0 ─► consumer 1     same key → same partition → same consumer
key=B ─► partition 1 ─► consumer 2
key=C ─► partition 0 ─► consumer 1
```

- ✔ **Order per key** is preserved, and different keys still run in parallel.
- ✘ Parallelism is capped by the number of partitions (extra consumers sit idle).
- ✘ A hot key overloads one partition, and one stuck message blocks everything behind it in that partition.

## In this folder

| File | Role |
| --- | --- |
| `WorkQueue.php` | An SQS-style queue: claim → invisible for a timeout → complete, or it comes back |
| `PartitionedConsumerGroup.php` | Key-hashed partitions with exactly one owner each, and reassignment when consumers come and go |

## What to notice

- The visibility timeout must be longer than the work takes, or a second worker starts the same message while the first is still on it (a heartbeat extends it). Either way delivery is at-least-once → handlers must be [idempotent](../../DataConsistency/Idempotency).
- `receiveCount` grows on every redelivery; that's the hook for [dead-lettering](../../Resilience/DeadLetterQueue) poison messages.
- Rule of thumb: independent jobs (send email, resize image) → competing consumers. Events about the same entity (account, order) → partition by that entity's id.

## Try it

```bash
php artisan test --filter=CompetingConsumersTest
```
