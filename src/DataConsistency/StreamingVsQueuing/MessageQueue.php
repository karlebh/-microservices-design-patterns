<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\StreamingVsQueuing;

use OutOfBoundsException;

/**
 * A message queue: each message is handed to ONE consumer and is deleted
 * once that consumer acknowledges it.
 */
final class MessageQueue
{
    /** @var list<array{id: int, payload: mixed}> waiting to be delivered */
    private array $ready = [];

    /** @var array<int, array{id: int, payload: mixed}> delivered but not yet acknowledged */
    private array $inFlight = [];

    private int $nextId = 1;

    public function send(mixed $payload): void
    {
        $this->ready[] = ['id' => $this->nextId++, 'payload' => $payload];
    }

    /**
     * Take the next message. It becomes invisible to other consumers until
     * it is acked (deleted) or nacked (returned).
     *
     * @return array{id: int, payload: mixed}|null
     */
    public function receive(): ?array
    {
        $message = array_shift($this->ready);

        if ($message === null) {
            return null;
        }

        return $this->inFlight[$message['id']] = $message;
    }

    /** Done: the message is gone for good. */
    public function ack(int $id): void
    {
        $this->assertInFlight($id);

        unset($this->inFlight[$id]);
    }

    /** Could not handle it: put it back at the front so someone can retry. */
    public function nack(int $id): void
    {
        $this->assertInFlight($id);

        array_unshift($this->ready, $this->inFlight[$id]);
        unset($this->inFlight[$id]);
    }

    /** Messages that still exist anywhere in the queue. */
    public function size(): int
    {
        return count($this->ready) + count($this->inFlight);
    }

    private function assertInFlight(int $id): void
    {
        if (! isset($this->inFlight[$id])) {
            throw new OutOfBoundsException("Message {$id} is not in flight.");
        }
    }
}
