<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ConsistentHashing;

use RuntimeException;

/**
 * A consistent hash ring with virtual nodes.
 *
 * Positions on the ring are 32-bit integers. Every physical node is placed
 * at `virtualNodes` positions; a key is owned by the node at the first
 * position clockwise from the key's own hash.
 */
final class HashRing
{
    /** @var array<int, string> ring position => physical node */
    private array $ring = [];

    /** @var list<int> the positions, sorted, for binary search */
    private array $positions = [];

    /** @param int $virtualNodes how many points each physical node gets on the ring */
    public function __construct(private readonly int $virtualNodes = 100) {}

    public function addNode(string $node): void
    {
        for ($replica = 0; $replica < $this->virtualNodes; $replica++) {
            $this->ring[$this->hash("{$node}#{$replica}")] = $node;
        }

        $this->sortPositions();
    }

    public function removeNode(string $node): void
    {
        $this->ring = array_filter($this->ring, fn (string $owner) => $owner !== $node);

        $this->sortPositions();
    }

    /** The node responsible for a key. */
    public function nodeFor(string $key): string
    {
        if ($this->positions === []) {
            throw new RuntimeException('The ring has no nodes.');
        }

        return $this->ring[$this->positions[$this->firstIndexClockwiseFrom($this->hash($key))]];
    }

    /**
     * The preference list: the next $count DISTINCT physical nodes clockwise
     * from the key. The first is the primary owner, the rest hold replicas.
     *
     * @return list<string>
     */
    public function nodesFor(string $key, int $count): array
    {
        $nodes = [];
        $total = count($this->positions);
        $index = $total === 0 ? 0 : $this->firstIndexClockwiseFrom($this->hash($key));

        for ($step = 0; $step < $total && count($nodes) < $count; $step++) {
            $node = $this->ring[$this->positions[($index + $step) % $total]];

            // Several virtual nodes of the same server can be adjacent: skip repeats.
            if (! in_array($node, $nodes, true)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * How many of the given keys each node owns.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function distribution(array $keys): array
    {
        $counts = array_fill_keys(array_unique(array_values($this->ring)), 0);

        foreach ($keys as $key) {
            $counts[$this->nodeFor($key)]++;
        }

        ksort($counts);

        return $counts;
    }

    /** Binary search for the first ring position >= $hash, wrapping around to the start. */
    private function firstIndexClockwiseFrom(int $hash): int
    {
        $low = 0;
        $high = count($this->positions);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->positions[$middle] < $hash) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low === count($this->positions) ? 0 : $low; // past the last position: wrap to the first
    }

    private function sortPositions(): void
    {
        $this->positions = array_keys($this->ring);
        sort($this->positions);
    }

    /** A well-mixed 32-bit position (the first 4 bytes of an MD5). */
    private function hash(string $value): int
    {
        return (int) hexdec(substr(md5($value), 0, 8));
    }
}
