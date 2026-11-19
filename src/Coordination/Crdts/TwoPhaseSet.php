<?php

declare(strict_types=1);

namespace Patterns\Coordination\Crdts;

/**
 * Two-phase set: a set that supports removal by keeping two grow-only sets.
 *
 *   added    everything that was ever added
 *   removed  everything that was ever removed (tombstones)
 *
 * An element is present if it was added and not removed. Because both sets
 * only grow, they merge by union. The consequence: removal is final. Once
 * an element is in `removed`, adding it again has no effect — remove wins.
 */
final class TwoPhaseSet
{
    private GSet $added;

    private GSet $removed;

    public function __construct()
    {
        $this->added = new GSet;
        $this->removed = new GSet;
    }

    public function add(string $element): void
    {
        $this->added->add($element);
    }

    public function remove(string $element): void
    {
        if ($this->added->contains($element)) {
            $this->removed->add($element);
        }
    }

    public function contains(string $element): bool
    {
        return $this->added->contains($element) && ! $this->removed->contains($element);
    }

    /** @return list<string> */
    public function elements(): array
    {
        return array_values(array_diff($this->added->elements(), $this->removed->elements()));
    }

    public function merge(self $other): void
    {
        $this->added->merge($other->added);
        $this->removed->merge($other->removed);
    }
}
