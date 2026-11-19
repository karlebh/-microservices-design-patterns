<?php

declare(strict_types=1);

namespace Patterns\Messaging\CompetingConsumers;

/**
 * Partitioned processing: messages are routed by key to a fixed set of
 * partitions, and each partition is consumed by exactly one consumer.
 *
 * Everything about one key is therefore handled by one consumer, one
 * message at a time, in the order it was published — while other keys are
 * handled in parallel by other consumers.
 */
final class PartitionedConsumerGroup
{
    /** @var list<list<array{key: string, payload: mixed}>> */
    private array $partitions;

    /** @var list<int> partition => index of the next unread message */
    private array $offsets;

    /** @var list<string> */
    private array $consumers = [];

    public function __construct(int $partitionCount)
    {
        $this->partitions = array_fill(0, $partitionCount, []);
        $this->offsets = array_fill(0, $partitionCount, 0);
    }

    /** @return int the partition the message landed in */
    public function publish(string $key, mixed $payload): int
    {
        $partition = $this->partitionOf($key);
        $this->partitions[$partition][] = ['key' => $key, 'payload' => $payload];

        return $partition;
    }

    public function partitionOf(string $key): int
    {
        return crc32($key) % count($this->partitions);
    }

    public function addConsumer(string $name): void
    {
        $this->consumers[] = $name;
    }

    public function removeConsumer(string $name): void
    {
        $this->consumers = array_values(array_diff($this->consumers, [$name]));
    }

    /**
     * Partition i belongs to consumer (i mod consumers). With more consumers
     * than partitions, the extra ones own nothing.
     *
     * @return list<int>
     */
    public function partitionsOf(string $consumer): array
    {
        $index = array_search($consumer, $this->consumers, true);

        if ($index === false) {
            return [];
        }

        return array_values(array_filter(
            array_keys($this->partitions),
            fn (int $partition) => $partition % count($this->consumers) === $index,
        ));
    }

    /**
     * Read everything new from the consumer's own partitions.
     *
     * @return list<array{partition: int, key: string, payload: mixed}>
     */
    public function poll(string $consumer): array
    {
        $messages = [];

        foreach ($this->partitionsOf($consumer) as $partition) {
            foreach (array_slice($this->partitions[$partition], $this->offsets[$partition]) as $message) {
                $messages[] = ['partition' => $partition] + $message;
            }

            $this->offsets[$partition] = count($this->partitions[$partition]);
        }

        return $messages;
    }

    /** Unread messages per partition: reveals skew caused by hot keys. */
    public function backlog(): array
    {
        return array_map(
            fn (array $messages, int $offset) => count($messages) - $offset,
            $this->partitions,
            $this->offsets,
        );
    }
}
