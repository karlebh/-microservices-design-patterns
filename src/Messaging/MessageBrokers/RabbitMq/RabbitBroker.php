<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageBrokers\RabbitMq;

use InvalidArgumentException;

/**
 * The RabbitMQ (AMQP 0-9-1) model in miniature.
 *
 * Producers never write to a queue. They publish to an EXCHANGE with a
 * ROUTING KEY, and the exchange copies the message into every queue whose
 * BINDING matches:
 *
 *   direct  - binding key equals the routing key
 *   fanout  - every bound queue, key ignored
 *   topic   - binding is a pattern: `*` = exactly one word, `#` = zero or more
 *
 * A queue hands each message to one consumer and keeps it until that
 * consumer acks it. A rejected message can be requeued or dead-lettered.
 */
final class RabbitBroker
{
    /** @var array<string, string> exchange => type */
    private array $exchanges = [];

    /** @var array<string, list<array{queue: string, pattern: string}>> exchange => bindings */
    private array $bindings = [];

    /** @var array<string, array{ready: list<array>, unacked: array<int, array>, deadLetterExchange: ?string}> */
    private array $queues = [];

    private int $nextDeliveryTag = 1;

    public function declareExchange(string $name, string $type): void
    {
        if (! in_array($type, ['direct', 'fanout', 'topic'], true)) {
            throw new InvalidArgumentException("Unsupported exchange type [{$type}].");
        }

        $this->exchanges[$name] = $type;
        $this->bindings[$name] ??= [];
    }

    public function declareQueue(string $name, ?string $deadLetterExchange = null): void
    {
        $this->queues[$name] = ['ready' => [], 'unacked' => [], 'deadLetterExchange' => $deadLetterExchange];
    }

    public function bind(string $queue, string $exchange, string $pattern = ''): void
    {
        $this->bindings[$exchange][] = ['queue' => $queue, 'pattern' => $pattern];
    }

    /**
     * @return int number of queues the message was copied into. 0 means it
     *             was unroutable and has been dropped.
     */
    public function publish(string $exchange, string $routingKey, mixed $body): int
    {
        $type = $this->exchanges[$exchange] ?? throw new InvalidArgumentException("Unknown exchange [{$exchange}].");
        $targets = [];

        foreach ($this->bindings[$exchange] as $binding) {
            $matches = match ($type) {
                'fanout' => true,
                'direct' => $binding['pattern'] === $routingKey,
                'topic' => $this->topicMatches(explode('.', $binding['pattern']), explode('.', $routingKey)),
            };

            if ($matches) {
                $targets[$binding['queue']] = true; // one copy per queue, even if several bindings match
            }
        }

        foreach (array_keys($targets) as $queue) {
            $this->queues[$queue]['ready'][] = ['body' => $body, 'routingKey' => $routingKey, 'redelivered' => false];
        }

        return count($targets);
    }

    /**
     * Take the next message. It stays "unacked" (owned by this consumer)
     * until ack() or nack() is called with its delivery tag.
     *
     * @return array{tag: int, body: mixed, routingKey: string, redelivered: bool}|null
     */
    public function consume(string $queue): ?array
    {
        $message = array_shift($this->queues[$queue]['ready']);

        if ($message === null) {
            return null;
        }

        $tag = $this->nextDeliveryTag++;
        $this->queues[$queue]['unacked'][$tag] = $message;

        return ['tag' => $tag] + $message;
    }

    public function ack(string $queue, int $tag): void
    {
        unset($this->queues[$queue]['unacked'][$tag]);
    }

    /**
     * Negative acknowledgement.
     *  - requeue: back to the front of the queue, flagged as redelivered
     *  - no requeue: sent to the queue's dead-letter exchange, or discarded
     */
    public function nack(string $queue, int $tag, bool $requeue = true): void
    {
        $message = $this->queues[$queue]['unacked'][$tag];
        unset($this->queues[$queue]['unacked'][$tag]);

        if ($requeue) {
            array_unshift($this->queues[$queue]['ready'], ['redelivered' => true] + $message);

            return;
        }

        $deadLetterExchange = $this->queues[$queue]['deadLetterExchange'];

        if ($deadLetterExchange !== null) {
            $this->publish($deadLetterExchange, $message['routingKey'], $message['body']);
        }
    }

    /** Messages waiting to be delivered. */
    public function depth(string $queue): int
    {
        return count($this->queues[$queue]['ready']);
    }

    /**
     * Topic matching, word by word.
     *
     * @param  list<string>  $pattern
     * @param  list<string>  $key
     */
    private function topicMatches(array $pattern, array $key): bool
    {
        if ($pattern === []) {
            return $key === [];
        }

        $head = array_shift($pattern);

        if ($head === '#') {
            // '#' may swallow zero, one or many words: try every split.
            for ($skip = 0; $skip <= count($key); $skip++) {
                if ($this->topicMatches($pattern, array_slice($key, $skip))) {
                    return true;
                }
            }

            return false;
        }

        if ($key === []) {
            return false;
        }

        $word = array_shift($key);

        return ($head === '*' || $head === $word) && $this->topicMatches($pattern, $key);
    }
}
