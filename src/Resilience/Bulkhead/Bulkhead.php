<?php

declare(strict_types=1);

namespace Patterns\Resilience\Bulkhead;

use Closure;

/**
 * A compartment that limits how many calls to one dependency may be in
 * progress at the same time (a counting semaphore).
 *
 * Usage:
 *   $bulkhead->acquire();          // may throw BulkheadFullException
 *   try { call the dependency } finally { $bulkhead->release(); }
 *
 * or simply `$bulkhead->execute(fn () => ...)`.
 *
 * PHP runs one request per process, so in this in-memory version
 * "concurrent" calls are simulated by acquiring without releasing. Across
 * real PHP workers the counter must live in shared storage (Redis).
 */
final class Bulkhead
{
    private int $inFlight = 0;

    private int $waiting = 0;

    /** How many calls were turned away. Worth alerting on. */
    public int $rejected = 0;

    /**
     * @param  int  $maxConcurrent  calls allowed in progress at once
     * @param  int  $maxWaiting  calls allowed to wait for a free slot; beyond that, reject immediately
     */
    public function __construct(
        public readonly string $name,
        private readonly int $maxConcurrent,
        private readonly int $maxWaiting = 0,
    ) {}

    /**
     * Take a slot.
     *
     * @return bool true if the call may run now, false if it has been queued and must wait for release()
     *
     * @throws BulkheadFullException when both the slots and the waiting queue are full
     */
    public function acquire(): bool
    {
        if ($this->inFlight < $this->maxConcurrent) {
            $this->inFlight++;

            return true;
        }

        if ($this->waiting < $this->maxWaiting) {
            $this->waiting++;

            return false;
        }

        $this->rejected++;

        throw BulkheadFullException::for($this->name);
    }

    /** Give the slot back. If someone is waiting, the slot goes straight to them. */
    public function release(): void
    {
        if ($this->waiting > 0) {
            $this->waiting--; // a queued call takes over the slot: inFlight stays the same

            return;
        }

        $this->inFlight = max(0, $this->inFlight - 1);
    }

    /** Run $work inside the compartment, always returning the slot afterwards. */
    public function execute(Closure $work): mixed
    {
        if (! $this->acquire()) {
            // A single-threaded demo cannot actually block, so a queued call is treated as rejected here.
            $this->waiting--;
            $this->rejected++;

            throw BulkheadFullException::for($this->name);
        }

        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    public function inFlight(): int
    {
        return $this->inFlight;
    }

    public function waiting(): int
    {
        return $this->waiting;
    }

    public function available(): int
    {
        return $this->maxConcurrent - $this->inFlight;
    }
}
