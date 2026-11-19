# Message Brokers Deep Dive (Kafka, Pulsar, RabbitMQ, NATS, ActiveMQ)

Brokers differ less in features than in their **core model** — what a message *is* and how the broker tracks who has seen it. Each subfolder is a small, working simulation of one broker's model, so the differences can be read as code and exercised in tests. (No real broker is required or contacted.)

| | Kafka | Pulsar | RabbitMQ | NATS (core) | ActiveMQ (JMS) |
| --- | --- | --- | --- | --- | --- |
| Core model | Partitioned, replicated **log** | Log + per-subscription **cursor** | **Exchange → binding → queue** | **Subjects**, no storage | **Queues** and **topics** |
| Broker tracks | One offset per group/partition | Each message's ack per subscription | Each message until acked | Nothing | Each message until acked |
| After consumption | Stays until retention | Stays until every subscription acked | Deleted | Never stored | Deleted |
| Replay | Yes (seek) | Yes (reset cursor) | No | No (JetStream adds it) | No |
| Ordering | Per partition | Per partition / per key | Per queue (1 consumer) | Per publisher | Per queue (1 consumer) |
| Routing | By key → partition | By key / subscription type | Rich: direct, fanout, topic patterns | Subject wildcards | Selectors on headers |
| Default guarantee | At-least-once | At-least-once | At-least-once (with acks) | **At-most-once** | At-least-once |
| Scaling consumers | Up to #partitions per group | Shared: unlimited | Competing consumers | Queue groups | Competing consumers |
| Sweet spot | Event streaming, high throughput, replay | Streaming **and** queuing, multi-tenant, geo-replication | Complex routing, task queues | Low-latency service messaging, request-reply | Enterprise/JMS integration |

## In this folder

| Folder | What the simulation shows |
| --- | --- |
| `Kafka/KafkaCluster.php` | Key → partition, consumer groups, rebalancing, commit vs position, seek/replay, log compaction |
| `RabbitMq/RabbitBroker.php` | Direct / fanout / topic exchanges, bindings, ack / nack / requeue, dead-letter exchange |
| `Nats/NatsServer.php` | Subject wildcards (`*`, `>`), fire-and-forget delivery, queue groups, request-reply via inboxes |
| `Pulsar/PulsarTopic.php` | The four subscription types (Exclusive, Failover, Shared, Key_Shared) and individual acknowledgements |
| `ActiveMq/ActiveMqBroker.php` | Queue vs topic, durable subscribers, message selectors, priorities |

## How to choose

- Need to **re-read history** or feed many independent consumers from one event stream → a log (Kafka, Pulsar).
- Need **flexible routing** or a classic work queue with per-message acks → RabbitMQ.
- Need **simple, very fast** service-to-service messaging and can tolerate loss (or add JetStream) → NATS.
- Need **both** queue and stream semantics on one system → Pulsar.
- Integrating with **JMS/enterprise** software → ActiveMQ.

Related: [Streaming vs Queuing](../../DataConsistency/StreamingVsQueuing), [Competing Consumers](../CompetingConsumers), [Message Ordering](../MessageOrdering).

## Try it

```bash
php artisan test --filter=MessageBrokersTest
```
