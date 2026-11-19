<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\Crdts\GCounter;
use Patterns\Coordination\Crdts\GSet;
use Patterns\Coordination\Crdts\LWWRegister;
use Patterns\Coordination\Crdts\ORSet;
use Patterns\Coordination\Crdts\PNCounter;
use Patterns\Coordination\Crdts\TwoPhaseSet;
use PHPUnit\Framework\TestCase;

final class CrdtsTest extends TestCase
{
    // ---- Counters -----------------------------------------------------------------------

    public function test_a_naive_replicated_counter_cannot_be_merged_correctly(): void
    {
        // Two replicas start at 0. One counts 3 likes, the other counts 2. The truth is 5.
        $onA = 3;
        $onB = 2;

        $this->assertSame(3, max($onA, $onB), 'Merging with max loses two likes.');

        // Summing looks right once...
        $merged = $onA + $onB;
        // ...but the next sync adds B's 2 again.
        $this->assertSame(7, $merged + $onB, 'Merging with sum counts them twice.');
    }

    public function test_a_g_counter_merges_concurrent_increments_without_losing_or_duplicating(): void
    {
        $a = new GCounter('A');
        $b = new GCounter('B');

        $a->increment(3);
        $b->increment(2);

        $a->merge($b);
        $b->merge($a);
        $a->merge($b); // syncing again changes nothing

        $this->assertSame(5, $a->value());
        $this->assertSame(5, $b->value());
    }

    public function test_a_pn_counter_handles_concurrent_increments_and_decrements(): void
    {
        $warehouseA = new PNCounter('A');
        $warehouseB = new PNCounter('B');

        $warehouseA->increment(10);     // 10 units arrive at A
        $warehouseB->merge($warehouseA);

        $warehouseA->decrement(3);      // A sells 3 while...
        $warehouseB->decrement(2);      // ...B sells 2
        $warehouseB->increment(5);      // and B restocks 5

        $warehouseA->merge($warehouseB);
        $warehouseB->merge($warehouseA);

        $this->assertSame(10, $warehouseA->value());
        $this->assertSame(10, $warehouseB->value(), '10 - 3 - 2 + 5, on both replicas.');
    }

    // ---- Sets ---------------------------------------------------------------------------

    public function test_a_g_set_converges_by_union(): void
    {
        $a = new GSet;
        $b = new GSet;

        $a->add('ada');
        $b->add('bob');
        $b->add('ada');

        $a->merge($b);

        $this->assertSame(['ada', 'bob'], $a->elements());
    }

    public function test_in_a_two_phase_set_a_removal_is_permanent(): void
    {
        $a = new TwoPhaseSet;
        $b = new TwoPhaseSet;

        $a->add('milk');
        $b->merge($a);

        $a->remove('milk');   // A takes milk off the list...
        $b->add('milk');      // ...while B (re-)adds it, unaware

        $a->merge($b);
        $b->merge($a);

        $this->assertFalse($a->contains('milk'));
        $this->assertFalse($b->contains('milk'), 'Remove wins, and milk can never be added again.');
    }

    public function test_in_an_or_set_a_concurrent_add_survives_a_remove(): void
    {
        $a = new ORSet('A');
        $b = new ORSet('B');

        $a->add('milk');
        $b->merge($a);

        $a->remove('milk');   // removes the add it has seen
        $b->add('milk');      // a NEW add, with a tag A has never seen

        $a->merge($b);
        $b->merge($a);

        $this->assertTrue($a->contains('milk'));
        $this->assertTrue($b->contains('milk'), 'Add wins: the remove only covered the first add.');
    }

    public function test_an_or_set_removal_that_has_seen_every_add_does_remove(): void
    {
        $a = new ORSet('A');
        $b = new ORSet('B');

        $a->add('milk');
        $b->merge($a);
        $b->remove('milk');
        $a->merge($b);

        $this->assertFalse($a->contains('milk'));

        $a->add('milk'); // and unlike a 2P-set, it can come back
        $this->assertSame(['milk'], $a->elements());
    }

    // ---- Register -----------------------------------------------------------------------

    public function test_a_lww_register_converges_on_the_newest_write(): void
    {
        $a = new LWWRegister('A');
        $b = new LWWRegister('B');

        $a->set('draft', timestamp: 100);
        $b->set('final', timestamp: 200);

        $a->merge($b);
        $b->merge($a);

        $this->assertSame('final', $a->value());
        $this->assertSame('final', $b->value());
    }

    public function test_a_lww_register_agrees_even_on_a_tie_but_one_write_is_lost(): void
    {
        $a = new LWWRegister('A');
        $b = new LWWRegister('B');

        $a->set('written on A', timestamp: 100);
        $b->set('written on B', timestamp: 100);

        $a->merge($b);
        $b->merge($a);

        $this->assertSame($a->value(), $b->value(), 'Convergence is guaranteed...');
        $this->assertSame('written on B', $a->value(), '...by a rule (higher replica name), not by merit. A\'s write is simply gone.');
    }

    // ---- The three laws -----------------------------------------------------------------

    /** Three replicas that each did something different, never having synced. */
    private function divergedCounters(): array
    {
        $counters = [new PNCounter('A'), new PNCounter('B'), new PNCounter('C')];

        $counters[0]->increment(4);
        $counters[1]->increment(7);
        $counters[1]->decrement(2);
        $counters[2]->decrement(1);

        return $counters;
    }

    public function test_merge_is_commutative(): void
    {
        [$a, $b] = $this->divergedCounters();
        [$a2, $b2] = $this->divergedCounters();

        $a->merge($b);     // a ∪ b
        $b2->merge($a2);   // b ∪ a

        $this->assertSame($a->value(), $b2->value());
    }

    public function test_merge_is_associative(): void
    {
        [$a, $b, $c] = $this->divergedCounters();
        [$a2, $b2, $c2] = $this->divergedCounters();

        $a->merge($b);
        $a->merge($c);     // (a ∪ b) ∪ c

        $b2->merge($c2);
        $a2->merge($b2);   // a ∪ (b ∪ c)

        $this->assertSame($a->value(), $a2->value());
    }

    public function test_merge_is_idempotent(): void
    {
        [$a, $b] = $this->divergedCounters();

        $a->merge($b);
        $once = $a->value();

        $a->merge($b);
        $a->merge($b);
        $a->merge($a);

        $this->assertSame($once, $a->value(), 'A duplicated or replayed sync message does no harm.');
    }

    public function test_replicas_converge_whatever_the_order_of_synchronisation(): void
    {
        $expected = null;

        // Every order in which three replicas can gossip pairwise until all have everything.
        foreach ([[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]] as $order) {
            $sets = [new ORSet('A'), new ORSet('B'), new ORSet('C')];
            $sets[0]->add('x');
            $sets[0]->add('y');
            $sets[1]->add('y');
            $sets[1]->remove('y');
            $sets[2]->add('z');

            foreach ([$order, array_reverse($order)] as $round) {
                foreach ($round as $i) {
                    foreach ($round as $j) {
                        $sets[$i]->merge($sets[$j]);
                    }
                }
            }

            $expected ??= $sets[0]->elements();

            foreach ($sets as $set) {
                $this->assertSame($expected, $set->elements());
            }
        }

        $this->assertSame(['x', 'y', 'z'], $expected, 'B removed only its own add of y; A\'s concurrent add of y survives.');
    }
}
