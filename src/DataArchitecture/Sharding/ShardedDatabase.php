<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Sharding;

use Closure;

/**
 * A database split over several shards, with the router in front of it.
 *
 * Each shard is an independent store (here an array; in reality a separate
 * database server). The router's job is to send each operation to as few
 * shards as possible.
 */
final class ShardedDatabase
{
    /** @var array<string, array<string, array>> shard => key => row */
    private array $shards = [];

    /** How many shards the most recent operation had to contact. */
    public int $shardsContacted = 0;

    /** @param list<string> $shardNames */
    public function __construct(
        private ShardingStrategy $strategy,
        array $shardNames,
    ) {
        foreach ($shardNames as $name) {
            $this->shards[$name] = [];
        }
    }

    /** @return string the shard the row was stored on */
    public function put(string $key, array $row): string
    {
        $shard = $this->strategy->shardFor($key);

        $this->shards[$shard][$key] = $row;
        $this->shardsContacted = 1;

        return $shard;
    }

    /** A lookup by shard key: the router knows exactly which shard to ask. */
    public function get(string $key): ?array
    {
        $this->shardsContacted = 1;

        return $this->shards[$this->strategy->shardFor($key)][$key] ?? null;
    }

    /**
     * A query that does not include the shard key. The router cannot know
     * where matching rows are, so it asks EVERY shard and merges the answers.
     *
     * @param  Closure(array): bool  $where
     * @return array<string, array> key => row
     */
    public function scatterGather(Closure $where): array
    {
        $this->shardsContacted = count($this->shards);
        $results = [];

        foreach ($this->shards as $rows) {
            $results += array_filter($rows, $where);
        }

        return $results;
    }

    /**
     * A query limited to specific shards (e.g. a range query under range sharding).
     *
     * @param  list<string>  $shardNames
     * @param  Closure(array, string): bool  $where  receives the row and its key
     * @return array<string, array>
     */
    public function queryShards(array $shardNames, Closure $where): array
    {
        $this->shardsContacted = count($shardNames);
        $results = [];

        foreach ($shardNames as $name) {
            $results += array_filter($this->shards[$name], $where, ARRAY_FILTER_USE_BOTH);
        }

        return $results;
    }

    /** @return array<string, int> shard => number of rows */
    public function sizes(): array
    {
        return array_map(count(...), $this->shards);
    }

    /**
     * Switch to a new strategy / shard layout and move every row that now
     * belongs somewhere else.
     *
     * @param  list<string>  $shardNames
     * @return int how many rows had to be moved between shards
     */
    public function reshard(ShardingStrategy $strategy, array $shardNames): int
    {
        $moved = 0;
        $layout = array_fill_keys($shardNames, []);

        foreach ($this->shards as $oldShard => $rows) {
            foreach ($rows as $key => $row) {
                $newShard = $strategy->shardFor((string) $key);
                $layout[$newShard][$key] = $row;

                if ($newShard !== $oldShard) {
                    $moved++;
                }
            }
        }

        $this->strategy = $strategy;
        $this->shards = $layout;

        return $moved;
    }
}
