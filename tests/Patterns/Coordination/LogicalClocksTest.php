<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\LogicalClocks\LamportClock;
use Patterns\Coordination\LogicalClocks\Ordering;
use Patterns\Coordination\LogicalClocks\Process;
use Patterns\Coordination\LogicalClocks\VectorClock;
use PHPUnit\Framework\TestCase;

final class LogicalClocksTest extends TestCase
{
    // ---- Lamport timestamps -------------------------------------------------------------

    public function test_a_lamport_clock_counts_local_events(): void
    {
        $clock = new LamportClock;

        $this->assertSame([1, 2, 3], [$clock->tick(), $clock->tick(), $clock->tick()]);
    }

    public function test_receiving_a_message_moves_the_clock_past_the_senders_time(): void
    {
        $clock = new LamportClock;
        $clock->tick(); // 1

        $this->assertSame(11, $clock->receive(10), 'A reply cannot be timestamped earlier than the request.');
        $this->assertSame(12, $clock->receive(3), 'An old message never moves the clock backwards.');
    }

    public function test_lamport_timestamps_respect_causality(): void
    {
        $a = new Process('A');
        $b = new Process('B');

        $write = $a->localEvent();
        $sent = $a->send();
        $received = $b->receive($sent);
        $reaction = $b->localEvent();

        $this->assertLessThan($sent['lamport'], $write['lamport']);
        $this->assertLessThan($received['lamport'], $sent['lamport'], 'Sent before received, always.');
        $this->assertLessThan($reaction['lamport'], $received['lamport']);
    }

    public function test_lamport_timestamps_cannot_tell_concurrent_events_from_ordered_ones(): void
    {
        $a = new Process('A');
        $b = new Process('B');

        // Two processes that never communicate.
        $a->localEvent();
        $a->localEvent();
        $eventOnA = $a->localEvent();  // lamport 3
        $eventOnB = $b->localEvent();  // lamport 1

        $this->assertLessThan($eventOnA['lamport'], $eventOnB['lamport'], 'The numbers suggest B\'s event came first...');
        $this->assertTrue($eventOnA['vector']->isConcurrentWith($eventOnB['vector']), '...but they were independent. Only the vector clock can say so.');
    }

    public function test_lamport_timestamps_give_every_process_the_same_total_order(): void
    {
        $events = [
            ['process' => 'B', 'lamport' => 2],
            ['process' => 'A', 'lamport' => 2],
            ['process' => 'C', 'lamport' => 1],
        ];

        // Sort by (timestamp, process id). Any node doing this gets the same sequence.
        usort($events, fn (array $x, array $y) => [$x['lamport'], $x['process']] <=> [$y['lamport'], $y['process']]);

        $this->assertSame(['C', 'A', 'B'], array_column($events, 'process'), 'Ties are broken by process id: arbitrary, but agreed upon.');
    }

    // ---- Vector clocks ------------------------------------------------------------------

    public function test_a_vector_clock_knows_how_much_it_has_seen_from_each_process(): void
    {
        $a = new Process('A');
        $b = new Process('B');

        $a->localEvent();
        $received = $b->receive($a->send());

        $this->assertSame(['A' => 2, 'B' => 1], $received['vector']->toArray(), 'B has seen two events of A and one of its own.');
    }

    public function test_vector_clocks_order_causally_related_events(): void
    {
        $a = new Process('A');
        $b = new Process('B');

        $sent = $a->send();
        $received = $b->receive($sent);

        $this->assertSame(Ordering::Before, $sent['vector']->compare($received['vector']));
        $this->assertSame(Ordering::After, $received['vector']->compare($sent['vector']));
        $this->assertSame(Ordering::Equal, $sent['vector']->compare($sent['vector']));
    }

    public function test_vector_clocks_detect_concurrent_updates(): void
    {
        $base = (new VectorClock)->increment('A');

        // Two replicas each modify the same record, starting from the same version.
        $editOnA = $base->increment('A');
        $editOnB = $base->increment('B');

        $this->assertSame(Ordering::Concurrent, $editOnA->compare($editOnB), 'Neither edit saw the other: this is a real conflict, not an ordering question.');
    }

    public function test_merging_resolves_concurrency_into_a_version_that_follows_both(): void
    {
        $editOnA = new VectorClock(['A' => 2]);
        $editOnB = new VectorClock(['A' => 1, 'B' => 1]);

        $resolved = $editOnA->merge($editOnB)->increment('A');

        $this->assertSame(['A' => 3, 'B' => 1], $resolved->toArray());
        $this->assertTrue($editOnA->happenedBefore($resolved));
        $this->assertTrue($editOnB->happenedBefore($resolved), 'The merged version supersedes both conflicting ones.');
    }

    public function test_vector_clocks_are_immutable(): void
    {
        $original = new VectorClock(['A' => 1]);
        $original->increment('A');
        $original->merge(new VectorClock(['B' => 5]));

        $this->assertSame(['A' => 1], $original->toArray(), 'A stamp attached to an event must never change.');
    }

    public function test_a_three_process_exchange(): void
    {
        $a = new Process('A');
        $b = new Process('B');
        $c = new Process('C');

        $fromA = $a->send();                  // A: {A:1}
        $onB = $b->receive($fromA);           // B: {A:1, B:1}
        $onC = $c->localEvent();              // C: {C:1}         independent of everything so far
        $fromB = $b->send();                  // B: {A:1, B:2}
        $merged = $c->receive($fromB);        // C: {A:1, B:2, C:2}

        $this->assertTrue($onC['vector']->isConcurrentWith($onB['vector']));
        $this->assertTrue($fromA['vector']->happenedBefore($merged['vector']), 'A\'s message reached C indirectly, through B.');
        $this->assertSame(['A' => 1, 'B' => 2, 'C' => 2], $merged['vector']->toArray());
    }
}
