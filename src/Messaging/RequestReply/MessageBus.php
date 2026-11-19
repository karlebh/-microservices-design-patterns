<?php

declare(strict_types=1);

namespace Patterns\Messaging\RequestReply;

/** Named point-to-point queues, in memory. */
final class MessageBus
{
    /** @var array<string, list<array>> */
    private array $queues = [];

    public function send(string $queue, array $message): void
    {
        $this->queues[$queue][] = $message;
    }

    public function receive(string $queue): ?array
    {
        if (empty($this->queues[$queue])) {
            return null;
        }

        return array_shift($this->queues[$queue]);
    }

    public function depth(string $queue): int
    {
        return count($this->queues[$queue] ?? []);
    }
}
