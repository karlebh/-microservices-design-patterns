<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\LsmVsBTree;

/**
 * A compact, probabilistic set.
 *
 * It answers "is this key in the set?" with either
 *   - "definitely not"  (always correct), or
 *   - "possibly"        (usually correct; sometimes a false positive).
 *
 * There are never false negatives. An LSM tree keeps one per SSTable so a
 * lookup can skip files that certainly don't contain the key, without
 * reading them.
 *
 * How: a bit array of size m and k hash functions. Adding a key sets k
 * bits. A key whose k bits are not ALL set was never added.
 */
final class BloomFilter
{
    /** @var array<int, true> the bits that are set (a sparse view of the bit array) */
    private array $bits = [];

    public function __construct(
        private readonly int $sizeBits = 2_048,
        private readonly int $hashCount = 4,
    ) {}

    public function add(string $key): void
    {
        foreach ($this->positions($key) as $position) {
            $this->bits[$position] = true;
        }
    }

    public function mightContain(string $key): bool
    {
        foreach ($this->positions($key) as $position) {
            if (! isset($this->bits[$position])) {
                return false; // one missing bit is proof of absence
            }
        }

        return true;
    }

    /**
     * k positions from two hashes (double hashing): position_i = h1 + i * h2.
     *
     * @return list<int>
     */
    private function positions(string $key): array
    {
        $h1 = crc32($key);
        $h2 = crc32('bloom:'.$key) | 1; // odd, so the steps cover the whole array

        $positions = [];

        for ($i = 0; $i < $this->hashCount; $i++) {
            $positions[] = ($h1 + $i * $h2) % $this->sizeBits;
        }

        return $positions;
    }
}
