<?php

declare(strict_types=1);

namespace Patterns\Coordination\Crdts;

/**
 * Observed-remove set.
 *
 * Every add is tagged with a unique id. A remove does not say "delete this
 * element"; it says "delete these specific adds that I have seen". An add
 * that happened concurrently on another replica has a tag the remover never
 * saw, so it survives the merge: ADD WINS over a concurrent remove.
 *
 * Unlike the two-phase set, an element can be removed and added again,
 * because the new add carries a fresh tag.
 */
final class ORSet
{
    /** @var array<string, array<string, true>> element => tags of the adds that introduced it */
    private array $tags = [];

    /** @var array<string, true> tags that have been removed (tombstones) */
    private array $removedTags = [];

    private int $sequence = 0;

    public function __construct(private readonly string $replica) {}

    public function add(string $element): void
    {
        $this->tags[$element]["{$this->replica}:".(++$this->sequence)] = true;
    }

    public function remove(string $element): void
    {
        // Remove exactly the adds this replica has observed so far.
        foreach (array_keys($this->tags[$element] ?? []) as $tag) {
            $this->removedTags[$tag] = true;
        }
    }

    public function contains(string $element): bool
    {
        return array_diff_key($this->tags[$element] ?? [], $this->removedTags) !== [];
    }

    /** @return list<string> sorted */
    public function elements(): array
    {
        $present = array_filter(array_map(strval(...), array_keys($this->tags)), $this->contains(...));
        sort($present);

        return $present;
    }

    public function merge(self $other): void
    {
        foreach ($other->tags as $element => $tags) {
            $this->tags[$element] = ($this->tags[$element] ?? []) + $tags;
        }

        $this->removedTags += $other->removedTags;
    }
}
