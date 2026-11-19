<?php

declare(strict_types=1);

namespace Patterns\Coordination\Crdts;

/**
 * Last-write-wins register: one value, stamped with the time it was set.
 *
 * Merging keeps whichever value has the higher timestamp (the replica name
 * breaks exact ties, so that all replicas choose the same winner).
 *
 * It is the simplest CRDT and the bluntest: all replicas agree, but of two
 * concurrent writes one is discarded, and "latest" is only as trustworthy
 * as the clocks that produced the timestamps.
 */
final class LWWRegister
{
    private mixed $value = null;

    private int $timestamp = 0;

    private string $writer = '';

    public function __construct(private readonly string $replica) {}

    public function set(mixed $value, int $timestamp): void
    {
        if ([$timestamp, $this->replica] > [$this->timestamp, $this->writer]) {
            $this->value = $value;
            $this->timestamp = $timestamp;
            $this->writer = $this->replica;
        }
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function merge(self $other): void
    {
        if ([$other->timestamp, $other->writer] > [$this->timestamp, $this->writer]) {
            $this->value = $other->value;
            $this->timestamp = $other->timestamp;
            $this->writer = $other->writer;
        }
    }
}
