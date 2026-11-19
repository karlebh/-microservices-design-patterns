<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Patterns\Resilience\Failover\ActiveActiveCluster;
use Patterns\Resilience\Failover\ActivePassiveCluster;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FailoverTest extends TestCase
{
    // ---- Active-passive -----------------------------------------------------------------

    public function test_active_passive_has_a_single_writer(): void
    {
        $cluster = new ActivePassiveCluster(['db-1', 'db-2'], new FakeClock);

        $cluster->write('balance', 100);

        $this->assertSame('db-1', $cluster->primary());
        $this->assertSame(100, $cluster->read('balance'));

        $this->expectException(RuntimeException::class);
        $cluster->writeAs('db-2', $cluster->epoch(), 'balance', 999); // the standby may not accept writes
    }

    public function test_a_standby_is_promoted_when_the_primary_stops_sending_heartbeats(): void
    {
        $clock = new FakeClock;
        $cluster = new ActivePassiveCluster(['db-1', 'db-2'], $clock, heartbeatTimeoutMs: 5_000);
        $cluster->write('balance', 100);
        $cluster->replicate();

        // db-1 dies. db-2 keeps reporting in.
        $clock->advance(4_000);
        $cluster->heartbeat('db-2');
        $this->assertFalse($cluster->checkPrimary(), 'Four seconds of silence is still within the timeout.');

        $clock->advance(2_000);
        $this->assertTrue($cluster->checkPrimary(), 'Six seconds is not: fail over.');

        $this->assertSame('db-2', $cluster->primary());
        $this->assertSame(100, $cluster->read('balance'), 'The replicated data is served by the new primary.');
    }

    public function test_the_recovery_time_is_at_least_the_detection_timeout(): void
    {
        $clock = new FakeClock;
        $cluster = new ActivePassiveCluster(['db-1', 'db-2'], $clock, heartbeatTimeoutMs: 5_000);

        $outageStartedAt = $clock->nowMs();

        do {
            $clock->advance(1_000);
        } while (! $cluster->checkPrimary());

        $this->assertSame(6_000, $clock->nowMs() - $outageStartedAt, 'RTO > 0: nobody could write during these six seconds.');
    }

    public function test_asynchronous_replication_loses_the_writes_that_were_still_in_transit(): void
    {
        $clock = new FakeClock;
        $cluster = new ActivePassiveCluster(['db-1', 'db-2'], $clock, synchronous: false);

        $cluster->write('order-1', 'paid');
        $cluster->replicate();
        $cluster->write('order-2', 'paid'); // acknowledged to the client, not yet on db-2

        $clock->advance(10_000);
        $cluster->checkPrimary();

        $this->assertSame('paid', $cluster->read('order-1'));
        $this->assertNull($cluster->read('order-2'), 'The client was told "ok", and the write is gone.');
        $this->assertSame(['order-2'], $cluster->lostWrites, 'RPO > 0.');
    }

    public function test_synchronous_replication_loses_nothing(): void
    {
        $clock = new FakeClock;
        $cluster = new ActivePassiveCluster(['db-1', 'db-2'], $clock, synchronous: true);

        $cluster->write('order-1', 'paid');
        $cluster->write('order-2', 'paid');

        $clock->advance(10_000);
        $cluster->checkPrimary();

        $this->assertSame('paid', $cluster->read('order-2'));
        $this->assertSame([], $cluster->lostWrites, 'RPO = 0, paid for with slower writes.');
    }

    public function test_an_old_primary_that_comes_back_is_fenced_off_by_its_stale_epoch(): void
    {
        $clock = new FakeClock;
        $cluster = new ActivePassiveCluster(['db-1', 'db-2'], $clock);
        $epochWhenDb1WasPrimary = $cluster->epoch();

        // db-1 was only cut off from the network. It still believes it is the primary.
        $clock->advance(10_000);
        $cluster->checkPrimary();
        $cluster->write('balance', 200); // handled by db-2, the new primary

        $this->assertSame($epochWhenDb1WasPrimary + 1, $cluster->epoch());

        try {
            $cluster->writeAs('db-1', $epochWhenDb1WasPrimary, 'balance', 50);
            $this->fail('A deposed primary must not be able to write: that would be split brain.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not the primary of epoch 2', $e->getMessage());
        }

        $this->assertSame(200, $cluster->read('balance'));

        // It rejoins as a follower of the new primary.
        $cluster->rejoin('db-1');
        $cluster->write('balance', 300);
        $cluster->replicate();

        $this->assertSame('db-2', $cluster->primary(), 'No automatic fail-back: one disruption is enough.');
    }

    // ---- Active-active ------------------------------------------------------------------

    public function test_active_active_accepts_writes_everywhere_and_converges(): void
    {
        $cluster = new ActiveActiveCluster(['eu', 'us'], new FakeClock);

        $cluster->write('eu', 'user:1', 'Ada');
        $cluster->write('us', 'user:2', 'Bob');

        $this->assertNull($cluster->read('us', 'user:1'), 'Replication is asynchronous.');

        $cluster->sync();

        $this->assertSame('Ada', $cluster->read('us', 'user:1'));
        $this->assertSame('Bob', $cluster->read('eu', 'user:2'));
    }

    public function test_active_active_needs_no_promotion_when_a_region_fails(): void
    {
        $cluster = new ActiveActiveCluster(['eu', 'us'], new FakeClock);
        $cluster->write('eu', 'cart:1', ['keyboard']);
        $cluster->sync();

        $cluster->fail('eu');

        $region = $cluster->route(preferredRegion: 'eu');

        $this->assertSame('us', $region, 'Traffic is simply sent to a region that is already serving.');
        $this->assertSame(['keyboard'], $cluster->read($region, 'cart:1'));

        $cluster->write($region, 'cart:1', ['keyboard', 'mouse']); // writes keep working: RTO ~ 0
        $this->assertSame(['keyboard', 'mouse'], $cluster->read('us', 'cart:1'));
    }

    public function test_concurrent_writes_to_the_same_key_conflict_and_one_is_silently_lost(): void
    {
        $clock = new FakeClock;
        $cluster = new ActiveActiveCluster(['eu', 'us'], $clock);

        // The same user edits their profile from two places before the regions have synced.
        $cluster->write('eu', 'user:1:email', 'ada@work.example');
        $clock->advance(5);
        $cluster->write('us', 'user:1:email', 'ada@home.example');

        $cluster->sync();

        $this->assertSame('ada@home.example', $cluster->read('eu', 'user:1:email'));
        $this->assertSame('ada@home.example', $cluster->read('us', 'user:1:email'), 'Both regions agree...');
        $this->assertSame(1, $cluster->conflictsResolved, '...because the earlier update was thrown away. Nobody was told.');
    }

    public function test_conflict_resolution_is_deterministic_even_for_simultaneous_writes(): void
    {
        $cluster = new ActiveActiveCluster(['eu', 'us'], new FakeClock);

        $cluster->write('eu', 'flag', 'from eu');
        $cluster->write('us', 'flag', 'from us'); // exactly the same timestamp

        $cluster->sync();

        $this->assertSame($cluster->read('eu', 'flag'), $cluster->read('us', 'flag'), 'A tie-breaker (region name) makes every region pick the same winner.');
    }

    public function test_a_recovered_region_catches_up(): void
    {
        $cluster = new ActiveActiveCluster(['eu', 'us'], new FakeClock);
        $cluster->fail('eu');

        $cluster->write('us', 'order:1', 'placed');
        $cluster->sync(); // eu is down: nothing reaches it

        $cluster->recover('eu');
        $this->assertNull($cluster->read('eu', 'order:1'));

        $cluster->sync();
        $this->assertSame('placed', $cluster->read('eu', 'order:1'));
    }
}
