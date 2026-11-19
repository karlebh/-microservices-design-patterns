<?php

declare(strict_types=1);

namespace Patterns\Messaging\CompetingConsumers;

use Patterns\Support\Clock;

/**
 * A queue for competing consumers, modelled on SQS.
 *
 * Claiming a message does not delete it. It becomes INVISIBLE for the
 * visibility timeout. The worker deletes it when the work is done; if the
 * worker dies instead, the timeout passes and the message is visible again
 * for another worker. No coordinator needs to notice the crash.
 */
final class WorkQueue
{
    /** @var array<int, array{id: int, payload: mixed, visibleAt: int, receiveCount: int}> */
    private array $messages = [];

    private int $nextId = 1;

    public function __construct(
        private readonly Clock $clock,
        private readonly int $visibilityTimeoutMs = 30_000,
    ) {}

    public function enqueue(mixed $payload): int
    {
        $id = $this->nextId++;
        $this->messages[$id] = ['id' => $id, 'payload' => $payload, 'visibleAt' => 0, 'receiveCount' => 0];

        return $id;
    }

    /**
     * Take the oldest visible message and hide it from everyone else.
     *
     * @return array{id: int, payload: mixed, receiveCount: int}|null
     */
    public function claim(): ?array
    {
        $now = $this->clock->nowMs();

        foreach ($this->messages as $id => $message) {
            if ($message['visibleAt'] > $now) {
                continue; // someone is (supposedly) working on it
            }

            $this->messages[$id]['visibleAt'] = $now + $this->visibilityTimeoutMs;
            $this->messages[$id]['receiveCount']++;

            return [
                'id' => $id,
                'payload' => $message['payload'],
                'receiveCount' => $this->messages[$id]['receiveCount'],
            ];
        }

        return null;
    }

    /** The work is done: delete the message so it is never delivered again. */
    public function complete(int $id): void
    {
        unset($this->messages[$id]);
    }

    /** "Still working on it": push the deadline out so nobody else picks it up. */
    public function heartbeat(int $id): void
    {
        if (isset($this->messages[$id])) {
            $this->messages[$id]['visibleAt'] = $this->clock->nowMs() + $this->visibilityTimeoutMs;
        }
    }

    /** Messages not yet completed, visible or not. */
    public function size(): int
    {
        return count($this->messages);
    }
}
