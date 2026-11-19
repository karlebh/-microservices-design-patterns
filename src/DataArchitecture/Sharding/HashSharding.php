<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Sharding;

/**
 * hash(key) mod N.
 *
 * The hash scrambles the keys, so even sequential ids spread evenly and no
 * shard is naturally hotter than another. The same scrambling means keys
 * that are "next to each other" end up on different shards, so a range
 * query has to visit all of them.
 *
 * And because the result depends on N, adding a shard reassigns nearly
 * every key.
 */
final class HashSharding implements ShardingStrategy
{
    /** @param list<string> $shards */
    public function __construct(private readonly array $shards) {}

    public function shardFor(string $key): string
    {
        return $this->shards[crc32($key) % count($this->shards)];
    }
}
