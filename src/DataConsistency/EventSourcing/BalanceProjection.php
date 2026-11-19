<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\EventSourcing;

/**
 * A read model: "balance per account" plus "total money in the bank".
 *
 * It is derived data. It remembers how far into the global log it has read,
 * catches up incrementally, and can be thrown away and rebuilt at any time.
 */
final class BalanceProjection
{
    /** @var array<string, int> account id => balance */
    private array $balances = [];

    private int $position = 0;

    public function __construct(private readonly EventStore $store) {}

    /** Process events that arrived since the last call. */
    public function catchUp(): void
    {
        foreach ($this->store->all(afterPosition: $this->position) as $record) {
            $cents = $record['event']->payload['cents'] ?? 0;

            $this->balances[$record['stream']] = match ($record['event']->type) {
                DomainEvent::ACCOUNT_OPENED => 0,
                DomainEvent::MONEY_DEPOSITED => $this->balances[$record['stream']] + $cents,
                DomainEvent::MONEY_WITHDRAWN => $this->balances[$record['stream']] - $cents,
            };

            $this->position = $record['position'];
        }
    }

    public function rebuild(): void
    {
        $this->balances = [];
        $this->position = 0;
        $this->catchUp();
    }

    public function balanceOf(string $accountId): ?int
    {
        return $this->balances[$accountId] ?? null;
    }

    public function totalDeposits(): int
    {
        return array_sum($this->balances);
    }
}
