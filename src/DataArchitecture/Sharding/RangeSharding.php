<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Sharding;

use OutOfRangeException;

/**
 * Each shard owns a contiguous range of keys.
 *
 * Keys that sort together live together, so "all users from d to f" or
 * "all events of last week" is answered by one shard. The cost is skew:
 * real keys are not evenly distributed, and keys that grow over time
 * (ids, timestamps) send every new write to the last shard.
 */
final class RangeSharding implements ShardingStrategy
{
    /**
     * @param  array<string, string>  $upperBounds  exclusive upper bound => shard, in ascending order.
     *                                              ['i' => 'shard-0', 'r' => 'shard-1', '~' => 'shard-2']
     *                                              means a-h, i-q, r-z.
     */
    public function __construct(private readonly array $upperBounds) {}

    public function shardFor(string $key): string
    {
        foreach ($this->upperBounds as $upperBound => $shard) {
            if (strcmp($key, (string) $upperBound) < 0) {
                return $shard;
            }
        }

        throw new OutOfRangeException("No shard covers the key [{$key}].");
    }

    /**
     * The shards a range query has to touch: usually one or two instead of all.
     *
     * @return list<string>
     */
    public function shardsForRange(string $from, string $to): array
    {
        $shards = [];
        $lowerBound = '';

        foreach ($this->upperBounds as $upperBound => $shard) {
            // The shard's range [lowerBound, upperBound) overlaps [from, to].
            if (strcmp($from, (string) $upperBound) < 0 && strcmp($to, $lowerBound) >= 0) {
                $shards[] = $shard;
            }

            $lowerBound = (string) $upperBound;
        }

        return $shards;
    }
}
