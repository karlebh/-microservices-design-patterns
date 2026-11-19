<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageBrokers\ActiveMq;

/**
 * The ActiveMQ / JMS model in miniature.
 *
 * JMS defines two kinds of destination:
 *
 *  - QUEUE (point-to-point): each message is consumed by exactly one
 *    receiver. Messages wait in the queue until someone takes them.
 *  - TOPIC (publish-subscribe): each message goes to every subscriber that
 *    is connected at that moment. A DURABLE subscriber is remembered by
 *    name, so messages published while it is offline are kept for it.
 *
 * Plus two JMS features worth knowing: message SELECTORS (filter on header
 * properties, evaluated by the broker) and message PRIORITY (0-9, default 4).
 */
final class ActiveMqBroker
{
    public const DEFAULT_PRIORITY = 4;

    /** @var array<string, list<array{body: mixed, properties: array, priority: int, sequence: int}>> */
    private array $queues = [];

    /** @var array<string, array<string, array{durable: bool, online: bool, selector: array, inbox: list<mixed>}>> topic => subscriber => state */
    private array $subscribers = [];

    private int $sequence = 0;

    // ---- Queues: point-to-point --------------------------------------------------------

    public function send(string $queue, mixed $body, array $properties = [], int $priority = self::DEFAULT_PRIORITY): void
    {
        $this->queues[$queue][] = [
            'body' => $body,
            'properties' => $properties,
            'priority' => $priority,
            'sequence' => $this->sequence++,
        ];
    }

    /**
     * Take the next message: highest priority first, then oldest first.
     *
     * @param  array<string, mixed>  $selector  only consider messages whose properties contain all of these values
     */
    public function receive(string $queue, array $selector = []): mixed
    {
        $best = null;

        foreach ($this->queues[$queue] ?? [] as $index => $message) {
            if (! $this->selects($selector, $message['properties'])) {
                continue;
            }

            if ($best === null
                || $message['priority'] > $this->queues[$queue][$best]['priority']
                || ($message['priority'] === $this->queues[$queue][$best]['priority']
                    && $message['sequence'] < $this->queues[$queue][$best]['sequence'])) {
                $best = $index;
            }
        }

        if ($best === null) {
            return null;
        }

        $body = $this->queues[$queue][$best]['body'];
        unset($this->queues[$queue][$best]);

        return $body;
    }

    // ---- Topics: publish-subscribe -----------------------------------------------------

    /** Connect a subscriber. Re-subscribing with the same name reconnects a durable subscription. */
    public function subscribe(string $topic, string $subscriber, bool $durable = false, array $selector = []): void
    {
        $existing = $this->subscribers[$topic][$subscriber] ?? null;

        $this->subscribers[$topic][$subscriber] = [
            'durable' => $durable,
            'online' => true,
            'selector' => $selector,
            'inbox' => $existing['inbox'] ?? [], // a durable subscriber finds its backlog waiting
        ];
    }

    public function disconnect(string $topic, string $subscriber): void
    {
        if ($this->subscribers[$topic][$subscriber]['durable']) {
            // The broker keeps the subscription and goes on collecting messages for it.
            $this->subscribers[$topic][$subscriber]['online'] = false;

            return;
        }

        // A non-durable subscription only exists while its consumer is connected.
        unset($this->subscribers[$topic][$subscriber]);
    }

    public function publish(string $topic, mixed $body, array $properties = []): void
    {
        foreach ($this->subscribers[$topic] ?? [] as $name => $subscriber) {
            if ($this->selects($subscriber['selector'], $properties)) {
                $this->subscribers[$topic][$name]['inbox'][] = $body;
            }
        }
    }

    /**
     * Everything delivered to the subscriber since it last looked.
     *
     * @return list<mixed>
     */
    public function drain(string $topic, string $subscriber): array
    {
        $messages = $this->subscribers[$topic][$subscriber]['inbox'] ?? [];

        if (isset($this->subscribers[$topic][$subscriber])) {
            $this->subscribers[$topic][$subscriber]['inbox'] = [];
        }

        return $messages;
    }

    /**
     * A JMS selector is a SQL-92 expression over message headers
     * (`region = 'EU' AND priority > 5`). Equality on every listed property
     * is enough to show the idea: filtering happens in the broker.
     */
    private function selects(array $selector, array $properties): bool
    {
        foreach ($selector as $name => $value) {
            if (($properties[$name] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
