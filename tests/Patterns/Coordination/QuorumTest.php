<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\Quorum\QuorumStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuorumTest extends TestCase
{
    public function test_overlapping_quorums_always_read_the_latest_write(): void
    {
        $store = new QuorumStore(n: 3, w: 2, r: 2);
        $this->assertTrue($store->guaranteesFreshReads());

        $store->write('cart', ['book']);                      // all three replicas
        $store->write('cart', ['book', 'pen'], reachable: [0, 1]); // replica 2 misses this one

        $this->assertSame(['book'], $store->valueOn(2, 'cart'), 'Replica 2 is stale...');

        // Whichever two replicas answer, at least one of them took part in the last write.
        foreach ([[0, 1], [0, 2], [1, 2], [2, 0], [2, 1]] as $answering) {
            $fresh = new QuorumStore(3, 2, 2);
            $fresh->write('cart', ['book']);
            $fresh->write('cart', ['book', 'pen'], reachable: [0, 1]);

            $this->assertSame(['book', 'pen'], $fresh->read('cart', reachable: $answering), '...yet no read can miss the latest value.');
        }
    }

    public function test_without_overlap_a_read_can_return_stale_data(): void
    {
        $store = new QuorumStore(n: 3, w: 1, r: 1);
        $this->assertFalse($store->guaranteesFreshReads());

        $store->write('cart', ['book']);
        $store->write('cart', ['book', 'pen'], reachable: [0]); // acknowledged by one replica: enough for W=1

        $this->assertSame(['book', 'pen'], $store->read('cart', reachable: [0]));
        $this->assertSame(['book'], $store->read('cart', reachable: [2]), 'Fast and always available, and sometimes wrong.');
    }

    public function test_a_write_is_rejected_when_too_few_replicas_acknowledge(): void
    {
        $store = new QuorumStore(n: 3, w: 2, r: 2);
        $store->fail(1);
        $store->fail(2);

        $this->assertFalse($store->write('cart', ['book']), 'One replica cannot make a quorum of two.');
        $this->assertNull($store->valueOn(0, 'cart'));
    }

    public function test_a_majority_quorum_tolerates_the_loss_of_a_minority(): void
    {
        $store = new QuorumStore(n: 5, w: 3, r: 3);
        $store->write('config', 'v1');

        $store->fail(0);
        $store->fail(1);

        $this->assertTrue($store->write('config', 'v2'), 'Three of five are still up.');
        $this->assertSame('v2', $store->read('config'));

        $store->fail(2);

        $this->assertFalse($store->write('config', 'v3'), 'With three down, no majority exists any more.');

        $this->expectException(RuntimeException::class);
        $store->read('config');
    }

    public function test_the_quorum_sizes_decide_which_operation_survives_a_failure(): void
    {
        $readOptimised = new QuorumStore(n: 3, w: 3, r: 1);
        $writeOptimised = new QuorumStore(n: 3, w: 1, r: 3);

        foreach ([$readOptimised, $writeOptimised] as $store) {
            $store->write('k', 'v');
            $store->fail(2);
        }

        $this->assertSame('v', $readOptimised->read('k'), 'W=3, R=1: reads keep working with a node down...');
        $this->assertFalse($readOptimised->write('k', 'v2'), '...but writes stop.');

        $this->assertTrue($writeOptimised->write('k', 'v2'), 'W=1, R=3: writes keep working...');
        $this->expectException(RuntimeException::class);
        $writeOptimised->read('k'); // ...but reads stop.
    }

    public function test_read_repair_brings_stale_replicas_up_to_date(): void
    {
        $store = new QuorumStore(n: 3, w: 2, r: 2);
        $store->write('cart', ['book']);
        $store->write('cart', ['book', 'pen'], reachable: [0, 1]);

        $store->read('cart', reachable: [1, 2]); // the read notices replica 2 is behind

        $this->assertSame(['book', 'pen'], $store->valueOn(2, 'cart'), 'Repaired as a side effect of being read.');
        $this->assertSame(1, $store->readRepairs);
    }

    public function test_hinted_handoff_keeps_writes_available_and_delivers_them_later(): void
    {
        $strict = new QuorumStore(n: 3, w: 3, r: 1);
        $sloppy = new QuorumStore(n: 3, w: 3, r: 1, sloppy: true);

        $strict->fail(2);
        $sloppy->fail(2);

        $this->assertFalse($strict->write('cart', ['book']), 'A strict quorum refuses.');
        $this->assertTrue($sloppy->write('cart', ['book']), 'A sloppy quorum lets another node stand in for the missing replica.');
        $this->assertSame(1, $sloppy->pendingHints());
        $this->assertNull($sloppy->valueOn(2, 'cart'));

        $sloppy->recover(2);

        $this->assertSame(['book'], $sloppy->valueOn(2, 'cart'), 'The hint was handed off when the replica returned.');
        $this->assertSame(0, $sloppy->pendingHints());
    }
}
