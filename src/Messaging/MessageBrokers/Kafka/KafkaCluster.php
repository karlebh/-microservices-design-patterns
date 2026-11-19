<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageBrokers\Kafka;

use InvalidArgumentException;

/**
 * The Kafka model in miniature.
 *
 *  - A TOPIC is split into PARTITIONS. Each partition is an append-only log;
 *    a record's position in it is its OFFSET.
 *  - Records with the same KEY always go to the same partition, so they stay
 *    in order relative to each other.
 *  - A CONSUMER GROUP shares a topic: each partition is owned by exactly one
 *    member. Different groups are independent and each see every record.
 *  - The broker stores no per-message state, only one committed offset per
 *    (group, partition). Consuming is advancing a number.
 */
final class KafkaCluster
{
    /** @var array<string, list<array<int, array{key: ?string, value: mixed}>>> topic => partition => offset => record */
    private array $topics = [];

    /** @var array<string, list<int>> topic => partition => next offset to assign */
    private array $logEnd = [];

    /** @var array<string, int> topic => counter used to spread records that have no key */
    private array $roundRobin = [];

    /** @var array<string, array{topic: string, members: list<string>, generation: int}> */
    private array $groups = [];

    /** @var array<string, array<int, int>> group => partition => next offset to read (durable) */
    private array $committed = [];

    /** @var array<string, array<int, int>> group => partition => next offset to read (in memory only) */
    private array $position = [];

    public function createTopic(string $topic, int $partitions): void
    {
        $this->topics[$topic] = array_fill(0, $partitions, []);
        $this->logEnd[$topic] = array_fill(0, $partitions, 0);
        $this->roundRobin[$topic] = 0;
    }

    // ---- Producing ---------------------------------------------------------------------

    /** @return array{partition: int, offset: int} */
    public function produce(string $topic, ?string $key, mixed $value): array
    {
        $partition = $this->partitionFor($topic, $key);
        $offset = $this->logEnd[$topic][$partition]++;

        $this->topics[$topic][$partition][$offset] = ['key' => $key, 'value' => $value];

        return ['partition' => $partition, 'offset' => $offset];
    }

    /** hash(key) mod partitions. (Real Kafka uses murmur2; any stable hash gives the same property.) */
    public function partitionFor(string $topic, ?string $key): int
    {
        $partitions = count($this->topics[$topic] ?? throw new InvalidArgumentException("Unknown topic [{$topic}]."));

        return $key === null
            ? $this->roundRobin[$topic]++ % $partitions
            : crc32($key) % $partitions;
    }

    // ---- Consumer groups ---------------------------------------------------------------

    public function joinGroup(string $group, string $member, string $topic): void
    {
        $this->groups[$group] ??= ['topic' => $topic, 'members' => [], 'generation' => 0];
        $this->groups[$group]['members'][] = $member;

        $this->rebalance($group);
    }

    public function leaveGroup(string $group, string $member): void
    {
        $this->groups[$group]['members'] = array_values(array_diff($this->groups[$group]['members'], [$member]));

        $this->rebalance($group);
    }

    /**
     * Partitions currently owned by a member. Partition i goes to member
     * (i mod members), so every partition has exactly one owner per group.
     *
     * @return list<int>
     */
    public function assignment(string $group, string $member): array
    {
        $members = $this->groups[$group]['members'];
        $index = array_search($member, $members, true);

        if ($index === false) {
            return [];
        }

        $partitions = array_keys($this->topics[$this->groups[$group]['topic']]);

        return array_values(array_filter($partitions, fn (int $p) => $p % count($members) === $index));
    }

    /**
     * Fetch the next records from the member's partitions and advance the
     * in-memory position. Nothing is durable until commit().
     *
     * @return list<array{partition: int, offset: int, key: ?string, value: mixed}>
     */
    public function poll(string $group, string $member, int $maxRecords = 100): array
    {
        $topic = $this->groups[$group]['topic'];
        $records = [];

        foreach ($this->assignment($group, $member) as $partition) {
            $offset = $this->position[$group][$partition] ?? 0;

            while ($offset < $this->logEnd[$topic][$partition] && count($records) < $maxRecords) {
                // Compaction leaves gaps: offsets are never renumbered.
                if (isset($this->topics[$topic][$partition][$offset])) {
                    $records[] = ['partition' => $partition, 'offset' => $offset] + $this->topics[$topic][$partition][$offset];
                }

                $offset++;
            }

            $this->position[$group][$partition] = $offset;
        }

        return $records;
    }

    /** Durably record how far the group has got ("commitSync"). */
    public function commit(string $group): void
    {
        $this->committed[$group] = $this->position[$group] ?? [];
    }

    /** Rewind (or skip ahead): the log is still there, so old records can be read again. */
    public function seek(string $group, int $partition, int $offset): void
    {
        $this->position[$group][$partition] = $offset;
    }

    /** Records produced but not yet committed by the group. */
    public function lag(string $group): int
    {
        $topic = $this->groups[$group]['topic'];
        $lag = 0;

        foreach ($this->logEnd[$topic] as $partition => $end) {
            $lag += $end - ($this->committed[$group][$partition] ?? 0);
        }

        return $lag;
    }

    public function generation(string $group): int
    {
        return $this->groups[$group]['generation'];
    }

    /**
     * Membership changed: ownership is redistributed, and every member
     * restarts from the last COMMITTED offsets. Anything that was polled
     * but not committed is delivered again, to whoever owns the partition now.
     */
    private function rebalance(string $group): void
    {
        $this->groups[$group]['generation']++;
        $this->position[$group] = $this->committed[$group] ?? [];
    }

    // ---- Log compaction ----------------------------------------------------------------

    /**
     * Keep only the newest record for each key. A compacted topic is a
     * changelog you can replay to get the latest state of every key.
     */
    public function compact(string $topic): void
    {
        foreach ($this->topics[$topic] as $partition => $records) {
            $latestOffsetByKey = [];

            foreach ($records as $offset => $record) {
                $latestOffsetByKey[$record['key'] ?? "\0null"] = $offset;
            }

            $this->topics[$topic][$partition] = array_intersect_key($records, array_flip($latestOffsetByKey));
        }
    }

    /** @return array<int, array{key: ?string, value: mixed}> offset => record */
    public function partitionContents(string $topic, int $partition): array
    {
        return $this->topics[$topic][$partition];
    }
}
