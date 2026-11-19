<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Saga\Choreography;

use Closure;

/**
 * Minimal in-memory publish/subscribe bus.
 *
 * Events published while another event is being handled are queued and
 * delivered afterwards (FIFO), the way a real broker would deliver them.
 */
final class EventBus
{
    /** @var array<string, list<Closure>> */
    private array $subscribers = [];

    /** @var list<array{name: string, payload: array}> */
    private array $queue = [];

    private bool $dispatching = false;

    /** @var list<string> every event name ever published, in order */
    public array $history = [];

    public function subscribe(string $event, Closure $handler): void
    {
        $this->subscribers[$event][] = $handler;
    }

    public function publish(string $event, array $payload = []): void
    {
        $this->history[] = $event;
        $this->queue[] = ['name' => $event, 'payload' => $payload];

        if ($this->dispatching) {
            return; // the outer loop below will pick it up
        }

        $this->dispatching = true;

        try {
            while ($next = array_shift($this->queue)) {
                foreach ($this->subscribers[$next['name']] ?? [] as $handler) {
                    $handler($next['payload']);
                }
            }
        } finally {
            $this->dispatching = false;
        }
    }
}
