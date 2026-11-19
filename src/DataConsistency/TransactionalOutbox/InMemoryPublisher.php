<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox;

use Patterns\DataConsistency\TransactionalOutbox\Models\OutboxMessage;
use RuntimeException;

/**
 * A fake broker for tests that can misbehave in the two ways that matter:
 *
 *  - outage:   the broker never receives the message
 *  - lost ack: the broker DOES receive it, but we never hear back
 *
 * The second one is the interesting case — it is why the outbox can only
 * promise at-least-once delivery.
 */
final class InMemoryPublisher implements MessagePublisher
{
    /** @var list<array{message_id: string, type: string, payload: array}> */
    public array $published = [];

    private int $outages = 0;

    private int $lostAcks = 0;

    public function failNext(int $times = 1): void
    {
        $this->outages = $times;
    }

    public function loseNextAck(int $times = 1): void
    {
        $this->lostAcks = $times;
    }

    public function publish(OutboxMessage $message): void
    {
        if ($this->outages > 0) {
            $this->outages--;

            throw new RuntimeException('Broker unavailable.');
        }

        $this->published[] = [
            'message_id' => $message->message_id,
            'type' => $message->type,
            'payload' => $message->payload,
        ];

        if ($this->lostAcks > 0) {
            $this->lostAcks--;

            throw new RuntimeException('Timed out waiting for the broker acknowledgement.');
        }
    }

    /** @return list<string> */
    public function publishedIds(): array
    {
        return array_column($this->published, 'message_id');
    }
}
