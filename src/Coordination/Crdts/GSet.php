<?php

declare(strict_types=1);

namespace Patterns\Coordination\Crdts;

/** Grow-only set: elements can be added, never removed. Merge is set union. */
final class GSet
{
    /** @var array<string, true> */
    private array $elements = [];

    public function add(string $element): void
    {
        $this->elements[$element] = true;
    }

    public function contains(string $element): bool
    {
        return isset($this->elements[$element]);
    }

    /** @return list<string> sorted */
    public function elements(): array
    {
        $elements = array_map(strval(...), array_keys($this->elements));
        sort($elements);

        return $elements;
    }

    public function merge(self $other): void
    {
        $this->elements += $other->elements;
    }
}
