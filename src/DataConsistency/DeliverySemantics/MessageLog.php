<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DeliverySemantics;

/**
 * The broker side: an ordered list of messages, plus the position each
 * consumer has acknowledged ("committed offset").
 *
 * Whatever lies beyond the committed offset is delivered again after a
 * restart. That single fact produces all three delivery guarantees.
 */
final class MessageLog
{
    /** @var list<array{offset: int, id: string, amount: int}> */
    private array $messages = [];

    /** @var array<string, int> consumer => offset of the next message it should receive */
    private array $committed = [];

    public function append(string $id, int $amount): void
    {
        $this->messages[] = ['offset' => count($this->messages), 'id' => $id, 'amount' => $amount];
    }

    /** @return array{offset: int, id: string, amount: int}|null */
    public function messageAt(int $offset): ?array
    {
        return $this->messages[$offset] ?? null;
    }

    public function committedOffset(string $consumer): int
    {
        return $this->committed[$consumer] ?? 0;
    }

    /** Acknowledge everything before $nextOffset. */
    public function commit(string $consumer, int $nextOffset): void
    {
        $this->committed[$consumer] = $nextOffset;
    }
}
