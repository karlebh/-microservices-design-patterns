<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Sharding;

/**
 * A lookup table decides where each key lives.
 *
 * Nothing is computed from the key, so placement is completely flexible:
 * move one noisy tenant to a dedicated shard, pin a customer to a region,
 * rebalance gradually. The price is that every request first needs the
 * directory, which therefore has to be highly available and cached.
 */
final class DirectorySharding implements ShardingStrategy
{
    /** @var array<string, string> key => shard */
    private array $directory = [];

    /** @param string $defaultShard where keys go until they are assigned somewhere specific */
    public function __construct(private readonly string $defaultShard) {}

    public function assign(string $key, string $shard): void
    {
        $this->directory[$key] = $shard;
    }

    public function shardFor(string $key): string
    {
        return $this->directory[$key] ?? $this->defaultShard;
    }
}
