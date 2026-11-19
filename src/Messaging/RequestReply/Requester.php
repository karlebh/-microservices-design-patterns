<?php

declare(strict_types=1);

namespace Patterns\Messaging\RequestReply;

use Patterns\Support\Clock;

/**
 * The asking side.
 *
 * Each requester instance owns a private reply queue. Every request it
 * sends says "answer me THERE, and quote THIS id", and the requester keeps
 * a table of the ids it is still waiting for.
 */
final class Requester
{
    /** Private to this instance, so its replies can't be taken by another one. */
    public readonly string $replyQueue;

    /** @var array<string, PendingReply> correlation id => who is waiting */
    private array $waiting = [];

    private int $sequence = 0;

    /** Replies that matched nothing: too late, duplicated, or not ours. */
    public int $discardedReplies = 0;

    public function __construct(
        private readonly MessageBus $bus,
        private readonly Clock $clock,
        private readonly string $name,
    ) {
        $this->replyQueue = "replies.{$name}";
    }

    public function request(string $queue, mixed $body, int $timeoutMs = 5_000): PendingReply
    {
        $correlationId = "{$this->name}-".(++$this->sequence);

        $this->bus->send($queue, [
            'correlation_id' => $correlationId,
            'reply_to' => $this->replyQueue,
            'body' => $body,
        ]);

        return $this->waiting[$correlationId] = new PendingReply($correlationId, $this->clock->nowMs() + $timeoutMs);
    }

    /**
     * Read the reply queue, hand each reply to the request it belongs to,
     * and give up on requests whose deadline has passed.
     */
    public function collectReplies(): void
    {
        // Expire first: a reply that arrives after its deadline is a late reply.
        foreach ($this->waiting as $correlationId => $pending) {
            if ($this->clock->nowMs() >= $pending->deadlineMs) {
                $pending->timeOut();
                unset($this->waiting[$correlationId]);
            }
        }

        while (($reply = $this->bus->receive($this->replyQueue)) !== null) {
            $pending = $this->waiting[$reply['correlation_id']] ?? null;

            if ($pending === null) {
                $this->discardedReplies++; // nobody is waiting for this any more

                continue;
            }

            $pending->fulfil($reply['body']);
            unset($this->waiting[$reply['correlation_id']]);
        }
    }
}
