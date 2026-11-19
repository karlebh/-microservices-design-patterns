<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\PublishSubscribe;

use Closure;
use Throwable;

/**
 * Topic-based publish/subscribe with fan-out: every subscriber of a topic
 * receives its own copy of every event.
 */
final class PubSubBroker
{
    /** @var array<string, array<string, Closure>> topic => subscriber name => handler */
    private array $subscriptions = [];

    /** @var list<array{topic: string, subscriber: string, error: string}> deliveries that threw */
    public array $failedDeliveries = [];

    public function subscribe(string $topic, string $subscriber, Closure $handler): void
    {
        $this->subscriptions[$topic][$subscriber] = $handler;
    }

    /** @return int how many subscribers received the event */
    public function publish(string $topic, array $event): int
    {
        $delivered = 0;

        foreach ($this->subscriptions[$topic] ?? [] as $subscriber => $handler) {
            try {
                $handler($event);
                $delivered++;
            } catch (Throwable $e) {
                // Subscribers are isolated: one failing must not stop the others
                // (or the publisher). A real broker would retry / dead-letter this.
                $this->failedDeliveries[] = ['topic' => $topic, 'subscriber' => $subscriber, 'error' => $e->getMessage()];
            }
        }

        return $delivered;
    }
}
