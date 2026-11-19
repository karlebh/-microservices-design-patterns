<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DeliverySemantics;

/**
 * Where the side effect lands. Adding to a balance is NOT idempotent:
 * applying the same message twice gives a wrong total.
 */
final class Ledger
{
    public int $balance = 0;

    /** @var list<string> ids of the messages applied, in order (duplicates show up here) */
    public array $applied = [];

    /** The input position stored together with the data. Only the exactly-once path uses it. */
    private int $nextOffset = 0;

    public function apply(string $messageId, int $amount): void
    {
        $this->balance += $amount;
        $this->applied[] = $messageId;
    }

    /**
     * One transaction: the effect AND the new input position.
     * Either both are stored or neither is — there is no in-between to crash in.
     */
    public function applyAndAdvance(string $messageId, int $amount, int $nextOffset): void
    {
        $this->apply($messageId, $amount);
        $this->nextOffset = $nextOffset;
    }

    public function nextOffset(): int
    {
        return $this->nextOffset;
    }
}
