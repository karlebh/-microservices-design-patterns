<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageOrdering;

/**
 * Producer side of ordering: number the messages of each key 1, 2, 3...
 *
 * A sequence number (unlike a timestamp) lets the consumer detect both
 * reordering and gaps: after 4 comes 5, and if 6 shows up first, 5 is missing.
 */
final class SequenceStamper
{
    /** @var array<string, int> key => last sequence handed out */
    private array $last = [];

    public function next(string $key): int
    {
        return $this->last[$key] = ($this->last[$key] ?? 0) + 1;
    }
}
