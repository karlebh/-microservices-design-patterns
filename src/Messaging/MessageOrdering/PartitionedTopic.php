<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageOrdering;

/**
 * A topic split into partitions by key.
 *
 * Within a partition, messages keep the order in which they were published.
 * Between partitions there is no order at all: consumers read them
 * independently and at different speeds.
 */
final class PartitionedTopic
{
    /** @var list<list<array{key: string, payload: mixed}>> */
    private array $partitions;

    public function __construct(int $partitionCount)
    {
        $this->partitions = array_fill(0, $partitionCount, []);
    }

    public function publish(string $key, mixed $payload): void
    {
        $this->partitions[$this->partitionOf($key)][] = ['key' => $key, 'payload' => $payload];
    }

    public function partitionOf(string $key): int
    {
        return crc32($key) % count($this->partitions);
    }

    /** @return list<array{key: string, payload: mixed}> one partition, in publish order */
    public function partition(int $index): array
    {
        return $this->partitions[$index];
    }

    /**
     * What consumers collectively see when partitions are drained in the
     * given order. Any order is legitimate; there is no "correct" one.
     *
     * @param  list<int>  $partitionOrder
     * @return list<array{key: string, payload: mixed}>
     */
    public function consume(array $partitionOrder): array
    {
        return array_merge(...array_map(fn (int $index) => $this->partitions[$index], $partitionOrder));
    }

    public function partitionCount(): int
    {
        return count($this->partitions);
    }
}
