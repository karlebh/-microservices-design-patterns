<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\EventSourcing;

use DomainException;

/**
 * An event-sourced aggregate.
 *
 * Rule of the pattern: state is changed ONLY inside apply(), and apply() is
 * driven ONLY by events. Command methods (deposit, withdraw) never touch the
 * balance directly; they check the rules and then record an event.
 *
 * That makes "current state" and "replayed state" the same code path.
 */
final class BankAccount
{
    private string $owner = '';

    private int $balance = 0;

    /** Number of events applied so far == position in the stream. */
    private int $version = 0;

    /** @var list<DomainEvent> recorded by commands but not yet saved */
    private array $pendingEvents = [];

    private function __construct(public readonly string $id) {}

    // ---- Commands: decide, then record -------------------------------------------------

    public static function open(string $id, string $owner): self
    {
        $account = new self($id);
        $account->record(new DomainEvent(DomainEvent::ACCOUNT_OPENED, ['owner' => $owner]));

        return $account;
    }

    public function deposit(int $cents): void
    {
        if ($cents <= 0) {
            throw new DomainException('A deposit must be positive.');
        }

        $this->record(new DomainEvent(DomainEvent::MONEY_DEPOSITED, ['cents' => $cents]));
    }

    public function withdraw(int $cents): void
    {
        if ($cents <= 0) {
            throw new DomainException('A withdrawal must be positive.');
        }

        // The invariant is checked against state that was itself derived from events.
        if ($cents > $this->balance) {
            throw new DomainException('Insufficient funds.');
        }

        $this->record(new DomainEvent(DomainEvent::MONEY_WITHDRAWN, ['cents' => $cents]));
    }

    // ---- Rebuilding from history -------------------------------------------------------

    /**
     * @param  iterable<DomainEvent>  $events
     * @param  array{owner: string, balance: int, version: int}|null  $snapshot  start from here instead of from zero
     */
    public static function fromHistory(string $id, iterable $events, ?array $snapshot = null): self
    {
        $account = new self($id);

        if ($snapshot !== null) {
            $account->owner = $snapshot['owner'];
            $account->balance = $snapshot['balance'];
            $account->version = $snapshot['version'];
        }

        foreach ($events as $event) {
            $account->apply($event);
        }

        return $account;
    }

    /** @return array{owner: string, balance: int, version: int} */
    public function snapshot(): array
    {
        return ['owner' => $this->owner, 'balance' => $this->balance, 'version' => $this->version];
    }

    // ---- Internals ---------------------------------------------------------------------

    private function record(DomainEvent $event): void
    {
        $this->apply($event);
        $this->pendingEvents[] = $event;
    }

    /** The only place where state changes. No validation here: events are facts. */
    private function apply(DomainEvent $event): void
    {
        match ($event->type) {
            DomainEvent::ACCOUNT_OPENED => $this->owner = $event->payload['owner'],
            DomainEvent::MONEY_DEPOSITED => $this->balance += $event->payload['cents'],
            DomainEvent::MONEY_WITHDRAWN => $this->balance -= $event->payload['cents'],
        };

        $this->version++;
    }

    /** @return list<DomainEvent> */
    public function pullPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function balance(): int
    {
        return $this->balance;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function version(): int
    {
        return $this->version;
    }
}
