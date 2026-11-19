<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\EventSourcing;

use OutOfBoundsException;

/**
 * Loads aggregates by replaying their events and saves them by appending
 * the new ones. Optionally keeps snapshots so long streams load fast.
 */
final class BankAccountRepository
{
    /** @var array<string, array{owner: string, balance: int, version: int}> */
    private array $snapshots = [];

    /** How many events the last get() had to replay. Shows what snapshots save. */
    public int $eventsReplayedOnLastLoad = 0;

    /**
     * @param  int  $snapshotEvery  take a snapshot each time a stream crosses a multiple of this (0 = never)
     */
    public function __construct(
        private readonly EventStore $store,
        private readonly int $snapshotEvery = 0,
    ) {}

    public function save(BankAccount $account): void
    {
        $events = $account->pullPendingEvents();

        // The version the aggregate had when it was loaded.
        $expectedVersion = $account->version() - count($events);

        $this->store->append($account->id, $expectedVersion, $events);

        if ($this->snapshotEvery > 0
            && intdiv($account->version(), $this->snapshotEvery) > intdiv($expectedVersion, $this->snapshotEvery)) {
            $this->snapshots[$account->id] = $account->snapshot();
        }
    }

    public function get(string $id): BankAccount
    {
        $snapshot = $this->snapshots[$id] ?? null;
        $events = $this->store->load($id, afterVersion: $snapshot['version'] ?? 0);

        if ($snapshot === null && $events === []) {
            throw new OutOfBoundsException("Account [{$id}] does not exist.");
        }

        $this->eventsReplayedOnLastLoad = count($events);

        return BankAccount::fromHistory($id, $events, $snapshot);
    }

    /** Time travel: the account as it was after its first $version events. */
    public function getAtVersion(string $id, int $version): BankAccount
    {
        return BankAccount::fromHistory($id, $this->store->load($id, untilVersion: $version));
    }
}
