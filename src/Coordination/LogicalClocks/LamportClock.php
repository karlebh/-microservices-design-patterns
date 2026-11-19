<?php

declare(strict_types=1);

namespace Patterns\Coordination\LogicalClocks;

/**
 * A Lamport clock: one integer that only moves forward.
 *
 * It does not measure time. It guarantees one thing: if event A could
 * have influenced event B, A's timestamp is smaller than B's.
 */
final class LamportClock
{
    private int $time = 0;

    /** A local event happened (or a message is about to be sent). */
    public function tick(): int
    {
        return ++$this->time;
    }

    /**
     * A message stamped with the sender's time arrived. Jump ahead of it:
     * receiving must be later than sending.
     */
    public function receive(int $senderTime): int
    {
        return $this->time = max($this->time, $senderTime) + 1;
    }

    public function time(): int
    {
        return $this->time;
    }
}
