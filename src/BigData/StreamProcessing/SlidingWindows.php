<?php

declare(strict_types=1);

namespace Patterns\BigData\StreamProcessing;

/**
 * Fixed-size windows that start every `slide` units and therefore overlap:
 * size 60, slide 30 gives [0, 60), [30, 90), [60, 120)...
 *
 * An event belongs to size / slide windows. That is what makes "the last
 * minute, updated every 30 seconds" possible.
 */
final class SlidingWindows implements WindowAssigner
{
    public function __construct(
        private readonly int $size,
        private readonly int $slide,
    ) {}

    public function assign(int $eventTime): array
    {
        $windows = [];

        // The latest window that starts at or before the event...
        $lastStart = intdiv($eventTime, $this->slide) * $this->slide;

        // ...and every earlier one that still reaches it.
        for ($start = $lastStart; $start > $eventTime - $this->size; $start -= $this->slide) {
            if ($start >= 0) {
                $windows[] = ['start' => $start, 'end' => $start + $this->size];
            }
        }

        return array_reverse($windows);
    }
}
