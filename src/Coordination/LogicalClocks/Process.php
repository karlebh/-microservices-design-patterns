<?php

declare(strict_types=1);

namespace Patterns\Coordination\LogicalClocks;

/**
 * A process in a distributed system, carrying both kinds of logical clock
 * so they can be compared on the same sequence of events.
 *
 * Each method returns the stamp of the event it performed:
 *   ['process' => name, 'lamport' => int, 'vector' => VectorClock]
 */
final class Process
{
    private LamportClock $lamport;

    private VectorClock $vector;

    public function __construct(public readonly string $name)
    {
        $this->lamport = new LamportClock;
        $this->vector = new VectorClock;
    }

    /** @return array{process: string, lamport: int, vector: VectorClock} */
    public function localEvent(): array
    {
        $this->lamport->tick();
        $this->vector = $this->vector->increment($this->name);

        return $this->stamp();
    }

    /**
     * Sending is an event too. The returned stamp travels with the message.
     *
     * @return array{process: string, lamport: int, vector: VectorClock}
     */
    public function send(): array
    {
        return $this->localEvent();
    }

    /**
     * @param  array{process: string, lamport: int, vector: VectorClock}  $message  the stamp from send()
     * @return array{process: string, lamport: int, vector: VectorClock}
     */
    public function receive(array $message): array
    {
        $this->lamport->receive($message['lamport']);
        $this->vector = $this->vector->merge($message['vector'])->increment($this->name);

        return $this->stamp();
    }

    /** @return array{process: string, lamport: int, vector: VectorClock} */
    private function stamp(): array
    {
        return ['process' => $this->name, 'lamport' => $this->lamport->time(), 'vector' => $this->vector];
    }
}
