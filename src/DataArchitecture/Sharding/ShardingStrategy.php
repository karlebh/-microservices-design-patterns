<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Sharding;

interface ShardingStrategy
{
    /** The name of the shard that owns this key. Must be deterministic. */
    public function shardFor(string $key): string;
}
