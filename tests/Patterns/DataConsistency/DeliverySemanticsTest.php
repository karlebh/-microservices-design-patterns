<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use Patterns\DataConsistency\DeliverySemantics\Consumer;
use Patterns\DataConsistency\DeliverySemantics\DeliveryGuarantee;
use Patterns\DataConsistency\DeliverySemantics\Ledger;
use Patterns\DataConsistency\DeliverySemantics\MessageLog;
use Patterns\DataConsistency\DeliverySemantics\ProcessCrashed;
use PHPUnit\Framework\TestCase;

final class DeliverySemanticsTest extends TestCase
{
    /**
     * Five deposits of 10 (correct total: 50). The consumer dies while
     * handling the third one, then restarts and finishes.
     */
    private function runWithACrash(DeliveryGuarantee $guarantee): Ledger
    {
        $log = new MessageLog;

        foreach (['m0', 'm1', 'm2', 'm3', 'm4'] as $id) {
            $log->append($id, 10);
        }

        $ledger = new Ledger;

        try {
            (new Consumer($log, $ledger, $guarantee))->run(crashAtOffset: 2);
            $this->fail('The consumer should have crashed.');
        } catch (ProcessCrashed) {
            // the process is restarted below
        }

        (new Consumer($log, $ledger, $guarantee))->run();

        return $ledger;
    }

    public function test_without_failures_all_three_guarantees_give_the_same_result(): void
    {
        foreach (DeliveryGuarantee::cases() as $guarantee) {
            $log = new MessageLog;
            $log->append('m0', 10);
            $log->append('m1', 10);
            $ledger = new Ledger;

            (new Consumer($log, $ledger, $guarantee))->run();

            $this->assertSame(20, $ledger->balance, "{$guarantee->name} behaves the same when nothing goes wrong.");
        }
    }

    public function test_at_most_once_loses_the_message_that_was_in_flight(): void
    {
        $ledger = $this->runWithACrash(DeliveryGuarantee::AtMostOnce);

        $this->assertSame(['m0', 'm1', 'm3', 'm4'], $ledger->applied, 'm2 was acknowledged but never applied.');
        $this->assertSame(40, $ledger->balance);
    }

    public function test_at_least_once_applies_the_message_that_was_in_flight_twice(): void
    {
        $ledger = $this->runWithACrash(DeliveryGuarantee::AtLeastOnce);

        $this->assertSame(['m0', 'm1', 'm2', 'm2', 'm3', 'm4'], $ledger->applied, 'm2 was applied, not acknowledged, then redelivered.');
        $this->assertSame(60, $ledger->balance);
    }

    public function test_exactly_once_neither_loses_nor_duplicates(): void
    {
        $ledger = $this->runWithACrash(DeliveryGuarantee::ExactlyOnce);

        $this->assertSame(['m0', 'm1', 'm2', 'm3', 'm4'], $ledger->applied);
        $this->assertSame(50, $ledger->balance);
    }

    public function test_exactly_once_resumes_from_the_position_stored_with_the_data(): void
    {
        $log = new MessageLog;
        $log->append('m0', 10);
        $log->append('m1', 10);
        $ledger = new Ledger;

        (new Consumer($log, $ledger, DeliveryGuarantee::ExactlyOnce))->run();

        $this->assertSame(2, $ledger->nextOffset(), 'The ledger itself knows how far it got.');
        $this->assertSame(0, $log->committedOffset('ledger-consumer'), "The broker's offset is not what it relies on.");
    }
}
