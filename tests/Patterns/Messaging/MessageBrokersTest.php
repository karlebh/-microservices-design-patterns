<?php

declare(strict_types=1);

namespace Tests\Patterns\Messaging;

use Patterns\Messaging\MessageBrokers\ActiveMq\ActiveMqBroker;
use Patterns\Messaging\MessageBrokers\Kafka\KafkaCluster;
use Patterns\Messaging\MessageBrokers\Nats\NatsServer;
use Patterns\Messaging\MessageBrokers\Pulsar\PulsarTopic;
use Patterns\Messaging\MessageBrokers\Pulsar\SubscriptionType;
use Patterns\Messaging\MessageBrokers\RabbitMq\RabbitBroker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MessageBrokersTest extends TestCase
{
    // ==== Kafka ==========================================================================

    public function test_kafka_keeps_records_with_the_same_key_in_one_partition_and_in_order(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('orders', partitions: 3);

        $partitions = [];

        foreach (['created', 'paid', 'shipped'] as $event) {
            $partitions[] = $kafka->produce('orders', 'order-42', $event)['partition'];
        }

        $this->assertCount(1, array_unique($partitions), 'Same key, same partition, every time.');
        $this->assertSame(
            ['created', 'paid', 'shipped'],
            array_column($kafka->partitionContents('orders', $partitions[0]), 'value'),
        );
    }

    public function test_kafka_spreads_keyless_records_across_partitions(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('clicks', partitions: 3);

        $partitions = array_map(fn () => $kafka->produce('clicks', null, 'click')['partition'], range(1, 6));

        $this->assertSame([0, 1, 2, 0, 1, 2], $partitions);
    }

    public function test_kafka_gives_each_partition_to_one_member_of_a_group(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('orders', partitions: 4);
        $kafka->joinGroup('billing', 'worker-a', 'orders');
        $kafka->joinGroup('billing', 'worker-b', 'orders');

        $this->assertSame([0, 2], $kafka->assignment('billing', 'worker-a'));
        $this->assertSame([1, 3], $kafka->assignment('billing', 'worker-b'));

        foreach (range(1, 8) as $i) {
            $kafka->produce('orders', null, "order {$i}");
        }

        $a = array_column($kafka->poll('billing', 'worker-a'), 'value');
        $b = array_column($kafka->poll('billing', 'worker-b'), 'value');

        $this->assertCount(8, array_merge($a, $b));
        $this->assertSame([], array_intersect($a, $b), 'Within a group, a record is processed by one member only.');
    }

    public function test_kafka_delivers_every_record_to_every_group(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('orders', partitions: 2);
        $kafka->joinGroup('billing', 'b-1', 'orders');
        $kafka->joinGroup('analytics', 'a-1', 'orders');

        $kafka->produce('orders', 'k', 'order placed');

        $this->assertCount(1, $kafka->poll('billing', 'b-1'));
        $this->assertCount(1, $kafka->poll('analytics', 'a-1'), 'Groups do not compete with each other.');
    }

    public function test_kafka_redelivers_uncommitted_records_after_a_rebalance(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('orders', partitions: 2);
        $kafka->joinGroup('billing', 'worker-a', 'orders');
        $kafka->joinGroup('billing', 'worker-b', 'orders');

        foreach (range(1, 4) as $i) {
            $kafka->produce('orders', null, "order {$i}");
        }

        $kafka->poll('billing', 'worker-a');
        $kafka->commit('billing');              // worker-a's progress is saved...
        $polled = $kafka->poll('billing', 'worker-b'); // ...worker-b polls but crashes before committing
        $this->assertCount(2, $polled);

        $generation = $kafka->generation('billing');
        $kafka->leaveGroup('billing', 'worker-b');

        $this->assertSame($generation + 1, $kafka->generation('billing'));
        $this->assertSame([0, 1], $kafka->assignment('billing', 'worker-a'), 'The survivor takes over all partitions.');
        $this->assertSame(
            array_column($polled, 'value'),
            array_column($kafka->poll('billing', 'worker-a'), 'value'),
            "worker-b's uncommitted records are processed again: at-least-once."
        );
    }

    public function test_kafka_can_replay_because_reading_does_not_delete(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('orders', partitions: 1);
        $kafka->joinGroup('search', 'indexer', 'orders');
        $kafka->produce('orders', 'a', 'one');
        $kafka->produce('orders', 'b', 'two');

        $this->assertCount(2, $kafka->poll('search', 'indexer'));
        $this->assertCount(0, $kafka->poll('search', 'indexer'));

        $kafka->seek('search', partition: 0, offset: 0);

        $this->assertSame(['one', 'two'], array_column($kafka->poll('search', 'indexer'), 'value'));
    }

    public function test_kafka_reports_consumer_lag(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('orders', partitions: 1);
        $kafka->joinGroup('billing', 'worker', 'orders');

        foreach (range(1, 5) as $i) {
            $kafka->produce('orders', 'k', $i);
        }

        $this->assertSame(5, $kafka->lag('billing'));

        $kafka->poll('billing', 'worker', maxRecords: 3);
        $kafka->commit('billing');

        $this->assertSame(2, $kafka->lag('billing'));
    }

    public function test_kafka_log_compaction_keeps_the_latest_value_per_key(): void
    {
        $kafka = new KafkaCluster;
        $kafka->createTopic('user-emails', partitions: 1);

        $kafka->produce('user-emails', 'ada', 'ada@old.example');
        $kafka->produce('user-emails', 'bob', 'bob@example.com');
        $kafka->produce('user-emails', 'ada', 'ada@new.example');

        $kafka->compact('user-emails');

        $this->assertSame(
            [1 => ['key' => 'bob', 'value' => 'bob@example.com'], 2 => ['key' => 'ada', 'value' => 'ada@new.example']],
            $kafka->partitionContents('user-emails', 0),
            'Offsets are preserved; only superseded records disappear.'
        );
    }

    // ==== RabbitMQ =======================================================================

    public function test_rabbitmq_direct_exchange_routes_on_an_exact_key(): void
    {
        $rabbit = new RabbitBroker;
        $rabbit->declareExchange('logs', 'direct');
        $rabbit->declareQueue('errors');
        $rabbit->declareQueue('everything');
        $rabbit->bind('errors', 'logs', 'error');
        $rabbit->bind('everything', 'logs', 'error');
        $rabbit->bind('everything', 'logs', 'info');

        $this->assertSame(2, $rabbit->publish('logs', 'error', 'disk full'));
        $this->assertSame(1, $rabbit->publish('logs', 'info', 'user logged in'));

        $this->assertSame(1, $rabbit->depth('errors'));
        $this->assertSame(2, $rabbit->depth('everything'));
    }

    public function test_rabbitmq_fanout_exchange_copies_to_every_bound_queue(): void
    {
        $rabbit = new RabbitBroker;
        $rabbit->declareExchange('order-events', 'fanout');

        foreach (['email', 'analytics', 'warehouse'] as $queue) {
            $rabbit->declareQueue($queue);
            $rabbit->bind($queue, 'order-events');
        }

        $this->assertSame(3, $rabbit->publish('order-events', 'ignored', 'order placed'));
    }

    public function test_rabbitmq_topic_exchange_routes_on_patterns(): void
    {
        $rabbit = new RabbitBroker;
        $rabbit->declareExchange('events', 'topic');
        $rabbit->declareQueue('eu-orders');
        $rabbit->declareQueue('all-orders');
        $rabbit->declareQueue('audit');
        $rabbit->bind('eu-orders', 'events', 'order.eu.*');   // exactly one more word
        $rabbit->bind('all-orders', 'events', 'order.#');     // anything under order
        $rabbit->bind('audit', 'events', '#');                // everything

        $this->assertSame(3, $rabbit->publish('events', 'order.eu.created', 'x'));
        $this->assertSame(2, $rabbit->publish('events', 'order.us.created', 'x'));
        $this->assertSame(2, $rabbit->publish('events', 'order.eu.created.retry', 'x'), '`*` matches one word, not two.');
        $this->assertSame(1, $rabbit->publish('events', 'payment.failed', 'x'));
    }

    public function test_rabbitmq_drops_messages_that_match_no_binding(): void
    {
        $rabbit = new RabbitBroker;
        $rabbit->declareExchange('logs', 'direct');
        $rabbit->declareQueue('errors');
        $rabbit->bind('errors', 'logs', 'error');

        $this->assertSame(0, $rabbit->publish('logs', 'debug', 'nobody wants this'));
        $this->assertSame(0, $rabbit->depth('errors'));
    }

    public function test_rabbitmq_keeps_a_message_until_it_is_acked_and_requeues_on_nack(): void
    {
        $rabbit = new RabbitBroker;
        $rabbit->declareExchange('jobs', 'direct');
        $rabbit->declareQueue('emails');
        $rabbit->bind('emails', 'jobs', 'email');
        $rabbit->publish('jobs', 'email', 'welcome ada');

        $first = $rabbit->consume('emails');
        $this->assertFalse($first['redelivered']);
        $this->assertNull($rabbit->consume('emails'), 'While unacked it is invisible to other consumers.');

        $rabbit->nack('emails', $first['tag'], requeue: true);

        $second = $rabbit->consume('emails');
        $this->assertTrue($second['redelivered'], 'The consumer can tell this is a retry.');

        $rabbit->ack('emails', $second['tag']);
        $this->assertNull($rabbit->consume('emails'), 'Acked means deleted.');
    }

    public function test_rabbitmq_dead_letters_rejected_messages(): void
    {
        $rabbit = new RabbitBroker;
        $rabbit->declareExchange('jobs', 'direct');
        $rabbit->declareExchange('dead-letters', 'fanout');
        $rabbit->declareQueue('emails', deadLetterExchange: 'dead-letters');
        $rabbit->declareQueue('failed-jobs');
        $rabbit->bind('emails', 'jobs', 'email');
        $rabbit->bind('failed-jobs', 'dead-letters');

        $rabbit->publish('jobs', 'email', 'malformed payload');
        $message = $rabbit->consume('emails');
        $rabbit->nack('emails', $message['tag'], requeue: false);

        $this->assertSame(0, $rabbit->depth('emails'));
        $this->assertSame('malformed payload', $rabbit->consume('failed-jobs')['body']);
    }

    // ==== NATS ===========================================================================

    public function test_nats_matches_subjects_with_wildcards(): void
    {
        $nats = new NatsServer;
        $received = [];

        $nats->subscribe('orders.*.created', function ($data, string $subject) use (&$received) {
            $received['one-token'][] = $subject;
        });
        $nats->subscribe('orders.>', function ($data, string $subject) use (&$received) {
            $received['tail'][] = $subject;
        });

        $nats->publish('orders.eu.created', null);
        $nats->publish('orders.eu.created.v2', null);
        $nats->publish('orders', null);

        $this->assertSame(['orders.eu.created'], $received['one-token']);
        $this->assertSame(['orders.eu.created', 'orders.eu.created.v2'], $received['tail'], '`>` needs at least one more token.');
    }

    public function test_nats_core_is_at_most_once_and_stores_nothing(): void
    {
        $nats = new NatsServer;

        $this->assertSame(0, $nats->publish('alerts', 'server on fire'), 'Nobody listening: the message is gone.');

        $received = [];
        $nats->subscribe('alerts', function ($data) use (&$received) {
            $received[] = $data;
        });

        $this->assertSame([], $received, 'A subscriber that arrives later does not get old messages.');

        $nats->publish('alerts', 'all good');
        $this->assertSame(['all good'], $received);
    }

    public function test_nats_queue_groups_load_balance_while_plain_subscribers_all_receive(): void
    {
        $nats = new NatsServer;
        $handled = ['worker-1' => 0, 'worker-2' => 0, 'auditor' => 0];

        foreach (['worker-1', 'worker-2'] as $worker) {
            $nats->subscribe('jobs', function () use (&$handled, $worker) {
                $handled[$worker]++;
            }, queueGroup: 'workers');
        }

        $nats->subscribe('jobs', function () use (&$handled) {
            $handled['auditor']++;
        });

        foreach (range(1, 4) as $i) {
            $nats->publish('jobs', "job {$i}");
        }

        $this->assertSame(['worker-1' => 2, 'worker-2' => 2, 'auditor' => 4], $handled);
    }

    public function test_nats_has_request_reply_built_in(): void
    {
        $nats = new NatsServer;

        $nats->subscribe('prices.get', function (string $sku, string $subject, ?string $replyTo) use ($nats) {
            $nats->publish($replyTo, ['sku' => $sku, 'price' => 4_599]);
        });

        $this->assertSame(['sku' => 'book', 'price' => 4_599], $nats->request('prices.get', 'book'));

        $this->expectException(RuntimeException::class);
        $nats->request('nobody.home', 'hello');
    }

    // ==== Pulsar =========================================================================

    public function test_pulsar_exclusive_subscription_admits_a_single_consumer(): void
    {
        $topic = new PulsarTopic;
        $topic->subscribe('billing', 'consumer-1', SubscriptionType::Exclusive);

        $this->expectException(RuntimeException::class);
        $topic->subscribe('billing', 'consumer-2', SubscriptionType::Exclusive);
    }

    public function test_pulsar_failover_subscription_promotes_the_standby(): void
    {
        $topic = new PulsarTopic;
        $topic->subscribe('billing', 'primary', SubscriptionType::Failover);
        $topic->subscribe('billing', 'standby', SubscriptionType::Failover);
        $topic->publish('m0');
        $topic->publish('m1');

        $this->assertNull($topic->receive('billing', 'standby'), 'Only the active consumer is served.');
        $this->assertSame('m0', $topic->receive('billing', 'primary')['value']); // received, not acked

        $topic->disconnect('billing', 'primary');

        $this->assertSame('standby', $topic->activeConsumer('billing'));
        $this->assertSame('m0', $topic->receive('billing', 'standby')['value'], 'The unacked message is redelivered to the new active consumer.');
    }

    public function test_pulsar_shared_subscription_is_a_work_queue_with_individual_acks(): void
    {
        $topic = new PulsarTopic;
        $topic->subscribe('workers', 'w1', SubscriptionType::Shared);
        $topic->subscribe('workers', 'w2', SubscriptionType::Shared);

        foreach (['m0', 'm1', 'm2'] as $value) {
            $topic->publish($value);
        }

        $first = $topic->receive('workers', 'w1');
        $second = $topic->receive('workers', 'w2');
        $this->assertSame(['m0', 'm1'], [$first['value'], $second['value']]);

        // Ack the NEWER message first. With offset-based acks this would also "ack" m0.
        $topic->acknowledge('workers', $second['id']);
        $this->assertSame(2, $topic->backlog('workers'), 'm0 and m2 are still owed.');

        // w1 fails on m0: it becomes available again, for any consumer.
        $topic->negativeAcknowledge('workers', $first['id']);
        $this->assertSame('m0', $topic->receive('workers', 'w2')['value']);
    }

    public function test_pulsar_key_shared_subscription_pins_each_key_to_one_consumer(): void
    {
        $topic = new PulsarTopic;
        $topic->subscribe('by-customer', 'c1', SubscriptionType::KeyShared);
        $topic->subscribe('by-customer', 'c2', SubscriptionType::KeyShared);

        foreach (['ada', 'bob', 'ada', 'cy', 'bob', 'ada'] as $customer) {
            $topic->publish("event for {$customer}", key: $customer);
        }

        $ownerOfKey = [];

        foreach (['c1', 'c2'] as $consumer) {
            while (($message = $topic->receive('by-customer', $consumer)) !== null) {
                $ownerOfKey[$message['key']][$consumer] = true;
                $topic->acknowledge('by-customer', $message['id']);
            }
        }

        $this->assertSame(0, $topic->backlog('by-customer'), 'Everything was consumed...');

        foreach ($ownerOfKey as $key => $owners) {
            $this->assertCount(1, $owners, "...and all messages for [{$key}] went to a single consumer.");
        }
    }

    public function test_pulsar_subscriptions_progress_independently_on_the_same_topic(): void
    {
        $topic = new PulsarTopic;
        $topic->subscribe('billing', 'b', SubscriptionType::Exclusive);
        $topic->publish('m0');
        $topic->publish('m1');

        $topic->acknowledge('billing', $topic->receive('billing', 'b')['id']);

        // A subscription created later can still ask for the whole history.
        $topic->subscribe('analytics', 'a', SubscriptionType::Exclusive, fromEarliest: true);

        $this->assertSame(1, $topic->backlog('billing'));
        $this->assertSame(2, $topic->backlog('analytics'));
        $this->assertSame('m0', $topic->receive('analytics', 'a')['value']);
    }

    // ==== ActiveMQ / JMS =================================================================

    public function test_activemq_queue_delivers_each_message_to_one_receiver_by_priority(): void
    {
        $broker = new ActiveMqBroker;
        $broker->send('support', 'routine question');
        $broker->send('support', 'site is down', priority: 9);
        $broker->send('support', 'another routine question');

        $this->assertSame('site is down', $broker->receive('support'), 'Higher priority jumps the queue.');
        $this->assertSame('routine question', $broker->receive('support'), 'Then first in, first out.');
        $this->assertSame('another routine question', $broker->receive('support'));
        $this->assertNull($broker->receive('support'));
    }

    public function test_activemq_selectors_let_the_broker_filter_for_a_consumer(): void
    {
        $broker = new ActiveMqBroker;
        $broker->send('orders', 'order from Berlin', ['region' => 'EU']);
        $broker->send('orders', 'order from Austin', ['region' => 'US']);

        $this->assertSame('order from Austin', $broker->receive('orders', ['region' => 'US']));
        $this->assertNull($broker->receive('orders', ['region' => 'US']), 'The EU order is still there, just not for this consumer.');
        $this->assertSame('order from Berlin', $broker->receive('orders'));
    }

    public function test_activemq_topic_only_durable_subscribers_get_messages_sent_while_offline(): void
    {
        $broker = new ActiveMqBroker;
        $broker->subscribe('prices', 'dashboard');                  // non-durable
        $broker->subscribe('prices', 'ledger', durable: true);      // durable

        $broker->publish('prices', 'EUR 1.08');

        $broker->disconnect('prices', 'dashboard');
        $broker->disconnect('prices', 'ledger');

        $broker->publish('prices', 'EUR 1.09'); // both are offline

        $broker->subscribe('prices', 'dashboard');
        $broker->subscribe('prices', 'ledger', durable: true);

        $this->assertSame([], $broker->drain('prices', 'dashboard'), 'Non-durable: what it missed is gone, and so is what it had not read.');
        $this->assertSame(['EUR 1.08', 'EUR 1.09'], $broker->drain('prices', 'ledger'), 'Durable: the broker kept everything for it.');
    }

    public function test_activemq_topic_fans_out_to_every_connected_subscriber(): void
    {
        $broker = new ActiveMqBroker;
        $broker->subscribe('prices', 'a');
        $broker->subscribe('prices', 'b', selector: ['currency' => 'EUR']);

        $broker->publish('prices', 'EUR 1.08', ['currency' => 'EUR']);
        $broker->publish('prices', 'GBP 0.86', ['currency' => 'GBP']);

        $this->assertSame(['EUR 1.08', 'GBP 0.86'], $broker->drain('prices', 'a'));
        $this->assertSame(['EUR 1.08'], $broker->drain('prices', 'b'));
    }
}
