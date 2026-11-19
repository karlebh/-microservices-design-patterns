<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use DomainException;
use Patterns\DataConsistency\EventSourcing\BalanceProjection;
use Patterns\DataConsistency\EventSourcing\BankAccount;
use Patterns\DataConsistency\EventSourcing\BankAccountRepository;
use Patterns\DataConsistency\EventSourcing\ConcurrencyException;
use Patterns\DataConsistency\EventSourcing\DomainEvent;
use Patterns\DataConsistency\EventSourcing\EventStore;
use PHPUnit\Framework\TestCase;

final class EventSourcingTest extends TestCase
{
    public function test_the_store_holds_what_happened_and_state_is_derived_from_it(): void
    {
        $store = new EventStore;
        $accounts = new BankAccountRepository($store);

        $account = BankAccount::open('acc-1', 'Ada');
        $account->deposit(100);
        $account->withdraw(30);
        $accounts->save($account);

        $this->assertSame(
            [DomainEvent::ACCOUNT_OPENED, DomainEvent::MONEY_DEPOSITED, DomainEvent::MONEY_WITHDRAWN],
            array_map(fn (DomainEvent $e) => $e->type, $store->load('acc-1')),
            'No balance column anywhere, only facts.'
        );

        $reloaded = $accounts->get('acc-1');
        $this->assertSame(70, $reloaded->balance());
        $this->assertSame('Ada', $reloaded->owner());
        $this->assertSame(3, $reloaded->version());
    }

    public function test_business_rules_are_checked_against_replayed_state(): void
    {
        $store = new EventStore;
        $accounts = new BankAccountRepository($store);

        $account = BankAccount::open('acc-1', 'Ada');
        $account->deposit(50);
        $accounts->save($account);

        $reloaded = $accounts->get('acc-1');

        try {
            $reloaded->withdraw(80);
            $this->fail('An overdraft should be refused.');
        } catch (DomainException) {
            // expected
        }

        $accounts->save($reloaded);
        $this->assertSame(2, $store->version('acc-1'), 'A rejected command leaves no event behind.');
    }

    public function test_concurrent_writers_are_detected_by_the_expected_version(): void
    {
        $store = new EventStore;
        $accounts = new BankAccountRepository($store);

        $account = BankAccount::open('acc-1', 'Ada');
        $account->deposit(100);
        $accounts->save($account);

        // Two requests load the same account (balance 100) at the same time.
        $first = $accounts->get('acc-1');
        $second = $accounts->get('acc-1');

        $first->withdraw(80);
        $second->withdraw(80); // valid given what it saw...

        $accounts->save($first);

        // ...but its decision is stale: saving it would overdraw the account.
        $this->expectException(ConcurrencyException::class);
        $accounts->save($second);
    }

    public function test_a_snapshot_shortens_the_replay_without_changing_the_result(): void
    {
        $store = new EventStore;
        $withSnapshots = new BankAccountRepository($store, snapshotEvery: 10);
        $withoutSnapshots = new BankAccountRepository($store);

        $account = BankAccount::open('acc-1', 'Ada');
        $withSnapshots->save($account);

        for ($i = 0; $i < 104; $i++) {
            $account->deposit(1);
            $withSnapshots->save($account);
        }

        $full = $withoutSnapshots->get('acc-1');
        $this->assertSame(105, $withoutSnapshots->eventsReplayedOnLastLoad);

        $fast = $withSnapshots->get('acc-1');
        $this->assertSame(5, $withSnapshots->eventsReplayedOnLastLoad, 'Snapshot at version 100, then 5 events.');

        $this->assertSame($full->balance(), $fast->balance());
        $this->assertSame($full->version(), $fast->version());
    }

    public function test_past_states_can_be_reconstructed(): void
    {
        $store = new EventStore;
        $accounts = new BankAccountRepository($store);

        $account = BankAccount::open('acc-1', 'Ada');
        $account->deposit(100);
        $account->withdraw(30);
        $account->deposit(5);
        $accounts->save($account);

        $this->assertSame(100, $accounts->getAtVersion('acc-1', 2)->balance(), 'Right after the first deposit.');
        $this->assertSame(70, $accounts->getAtVersion('acc-1', 3)->balance());
        $this->assertSame(75, $accounts->get('acc-1')->balance());
    }

    public function test_a_projection_follows_the_log_and_can_be_rebuilt_from_scratch(): void
    {
        $store = new EventStore;
        $accounts = new BankAccountRepository($store);
        $projection = new BalanceProjection($store);

        $ada = BankAccount::open('acc-1', 'Ada');
        $ada->deposit(100);
        $accounts->save($ada);

        $bob = BankAccount::open('acc-2', 'Bob');
        $bob->deposit(40);
        $accounts->save($bob);

        $this->assertNull($projection->balanceOf('acc-1'), 'Read models lag until they catch up.');

        $projection->catchUp();
        $this->assertSame(100, $projection->balanceOf('acc-1'));
        $this->assertSame(140, $projection->totalDeposits());

        $ada->withdraw(25);
        $accounts->save($ada);
        $projection->catchUp();
        $this->assertSame(75, $projection->balanceOf('acc-1'));

        // A brand-new read model, created long after the events, gets the same answer.
        $late = new BalanceProjection($store);
        $late->rebuild();
        $this->assertSame(115, $late->totalDeposits());
    }
}
