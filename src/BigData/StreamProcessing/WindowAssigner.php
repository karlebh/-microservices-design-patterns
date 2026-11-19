<?php

declare(strict_types=1);

namespace Patterns\BigData\StreamProcessing;

interface WindowAssigner
{
    /**
     * The windows an event with this timestamp falls into.
     *
     * @return list<array{start: int, end: int}> each window covers [start, end)
     */
    public function assign(int $eventTime): array;
}
