<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageBrokers\Pulsar;

use RuntimeException;

/**
 * The Pulsar model in miniature.
 *
 * A topic is a durable log (stored in BookKeeper, separately from the
 * brokers that serve it). Consumers attach through a named SUBSCRIPTION,
 * and each subscription keeps its own record of what has been acknowledged.
 *
 * Two things distinguish it from Kafka:
 *  - the subscription TYPE chooses the consumption style (see SubscriptionType)
 *  - acknowledgements are PER MESSAGE, not "everything up to offset N", so a
 *    consumer can ack message 5 while message 4 is still being worked on
 */
final class PulsarTopic
{
    /** @var list<array{id: int, key: ?string, value: mixed}> */
    private array $messages = [];

    /**
     * @var array<string, array{
     *     type: SubscriptionType,
     *     consumers: list<string>,
     *     startAt: int,
     *     acked: array<int, true>,
     *     pending: array<int, string>
     * }>
     */
    private array $subscriptions = [];

    /** @return int the message id */
    public function publish(mixed $value, ?string $key = null): int
    {
        $id = count($this->messages);
        $this->messages[] = ['id' => $id, 'key' => $key, 'value' => $value];

        return $id;
    }

    /**
     * Attach a consumer to a subscription, creating the subscription on
     * first use. A new subscription starts at the end of the topic unless
     * $fromEarliest is set.
     */
    public function subscribe(string $subscription, string $consumer, SubscriptionType $type = SubscriptionType::Exclusive, bool $fromEarliest = false): void
    {
        $this->subscriptions[$subscription] ??= [
            'type' => $type,
            'consumers' => [],
            'startAt' => $fromEarliest ? 0 : count($this->messages),
            'acked' => [],
            'pending' => [],
        ];

        if ($this->subscriptions[$subscription]['type'] === SubscriptionType::Exclusive
            && $this->subscriptions[$subscription]['consumers'] !== []) {
            throw new RuntimeException("Subscription [{$subscription}] is exclusive and already has a consumer.");
        }

        $this->subscriptions[$subscription]['consumers'][] = $consumer;
    }

    /**
     * A consumer goes away. Whatever it had received but not acknowledged
     * becomes available again. The subscription and its progress remain.
     */
    public function disconnect(string $subscription, string $consumer): void
    {
        $state = &$this->subscriptions[$subscription];

        $state['consumers'] = array_values(array_diff($state['consumers'], [$consumer]));
        $state['pending'] = array_filter($state['pending'], fn (string $owner) => $owner !== $consumer);
    }

    /**
     * Next message this consumer may have, or null.
     *
     * @return array{id: int, key: ?string, value: mixed}|null
     */
    public function receive(string $subscription, string $consumer): ?array
    {
        $state = &$this->subscriptions[$subscription];

        foreach (array_slice($this->messages, $state['startAt']) as $message) {
            if (isset($state['acked'][$message['id']]) || isset($state['pending'][$message['id']])) {
                continue;
            }

            if (! $this->mayReceive($state, $consumer, $message)) {
                // Exclusive / Failover: this consumer gets nothing at all.
                // Key_Shared: this message belongs to someone else, keep looking.
                if ($state['type'] === SubscriptionType::KeyShared) {
                    continue;
                }

                return null;
            }

            $state['pending'][$message['id']] = $consumer;

            return $message;
        }

        return null;
    }

    /** Acknowledge ONE message. Others, even older ones, are unaffected. */
    public function acknowledge(string $subscription, int $messageId): void
    {
        unset($this->subscriptions[$subscription]['pending'][$messageId]);
        $this->subscriptions[$subscription]['acked'][$messageId] = true;
    }

    /** "I couldn't process this": make it available for redelivery. */
    public function negativeAcknowledge(string $subscription, int $messageId): void
    {
        unset($this->subscriptions[$subscription]['pending'][$messageId]);
    }

    /** Messages the subscription has not acknowledged yet. */
    public function backlog(string $subscription): int
    {
        $state = $this->subscriptions[$subscription];

        return count($this->messages) - $state['startAt'] - count($state['acked']);
    }

    /** The consumer currently receiving messages in a Failover subscription. */
    public function activeConsumer(string $subscription): ?string
    {
        return $this->subscriptions[$subscription]['consumers'][0] ?? null;
    }

    private function mayReceive(array $state, string $consumer, array $message): bool
    {
        if (! in_array($consumer, $state['consumers'], true)) {
            return false;
        }

        return match ($state['type']) {
            // Only the first consumer in line is served.
            SubscriptionType::Exclusive, SubscriptionType::Failover => $state['consumers'][0] === $consumer,
            // Whoever asks first gets the next message.
            SubscriptionType::Shared => true,
            // The key decides the consumer, so one key's messages stay in order.
            SubscriptionType::KeyShared => $state['consumers'][crc32((string) $message['key']) % count($state['consumers'])] === $consumer,
        };
    }
}
