<?php

declare(strict_types=1);

namespace Patterns\BigData\StreamProcessing;

/**
 * Fixed-size windows laid end to end: [0, 60), [60, 120), [120, 180)...
 * Every event belongs to exactly one.
 */
final class TumblingWindows implements WindowAssigner
{
    public function __construct(private readonly int $size) {}

    public function assign(int $eventTime): array
    {
        $start = intdiv($eventTime, $this->size) * $this->size;

        return [['start' => $start, 'end' => $start + $this->size]];
    }
}
