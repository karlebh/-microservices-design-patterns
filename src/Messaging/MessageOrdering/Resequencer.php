<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageOrdering;

use Closure;

/**
 * Consumer side of ordering: turn "arrives in any order, maybe twice" back
 * into "exactly once each, in sequence".
 *
 * For every key it remembers the next sequence number it expects:
 *
 *   sequence == expected  -> deliver it, then flush buffered successors
 *   sequence  > expected  -> there is a gap: hold it in a buffer
 *   sequence  < expected  -> already delivered: a duplicate, drop it
 */
final class Resequencer
{
    /** @var array<string, int> key => next sequence number to deliver */
    private array $expected = [];

    /** @var array<string, array<int, mixed>> key => sequence => payload, waiting for a gap to close */
    private array $buffer = [];

    public int $duplicatesDropped = 0;

    /** @param Closure(string $key, int $sequence, mixed $payload): void $deliver called strictly in order per key */
    public function __construct(
        private readonly Closure $deliver,
        private readonly int $firstSequence = 1,
    ) {}

    public function accept(string $key, int $sequence, mixed $payload): void
    {
        $expected = $this->expected[$key] ?? $this->firstSequence;

        if ($sequence < $expected || array_key_exists($sequence, $this->buffer[$key] ?? [])) {
            $this->duplicatesDropped++;

            return;
        }

        $this->buffer[$key][$sequence] = $payload;

        // Deliver the longest run that is now contiguous.
        while (array_key_exists($expected, $this->buffer[$key])) {
            ($this->deliver)($key, $expected, $this->buffer[$key][$expected]);

            unset($this->buffer[$key][$expected]);
            $expected++;
        }

        $this->expected[$key] = $expected;
    }

    /** How many messages of this key are being held back. */
    public function buffered(string $key): int
    {
        return count($this->buffer[$key] ?? []);
    }

    /** The missing sequence number that is blocking this key, if any. */
    public function waitingFor(string $key): ?int
    {
        return $this->buffered($key) > 0 ? $this->expected[$key] ?? $this->firstSequence : null;
    }
}
