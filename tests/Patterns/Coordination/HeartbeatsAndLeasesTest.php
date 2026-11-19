<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\HeartbeatsAndLeases\HeartbeatFailureDetector;
use Patterns\Coordination\HeartbeatsAndLeases\LeaseManager;
use Patterns\Coordination\HeartbeatsAndLeases\PhiAccrualFailureDetector;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class HeartbeatsAndLeasesTest extends TestCase
{
    // ---- Timeout-based failure detection ------------------------------------------------

    public function test_a_node_is_considered_alive_while_its_heartbeats_keep_arriving(): void
    {
        $clock = new FakeClock;
        $detector = new HeartbeatFailureDetector($clock, timeoutMs: 3_000);

        foreach (range(1, 10) as $_) {
            $detector->heartbeat('node-a');
            $clock->advance(1_000);
        }

        $this->assertTrue($detector->isAlive('node-a'));
    }

    public function test_a_silent_node_is_suspected_after_the_timeout(): void
    {
        $clock = new FakeClock;
        $detector = new HeartbeatFailureDetector($clock, timeoutMs: 3_000);
        $detector->heartbeat('node-a');
        $detector->heartbeat('node-b');

        $clock->advance(2_500);
        $detector->heartbeat('node-b'); // node-a has gone quiet

        $clock->advance(1_000);

        $this->assertSame(['node-a'], $detector->suspected());
        $this->assertTrue($detector->isAlive('node-b'));
    }

    public function test_a_suspected_node_is_trusted_again_when_it_reports_back(): void
    {
        $clock = new FakeClock;
        $detector = new HeartbeatFailureDetector($clock, timeoutMs: 3_000);
        $detector->heartbeat('node-a');

        $clock->advance(10_000);
        $this->assertFalse($detector->isAlive('node-a'), 'Suspected...');

        $detector->heartbeat('node-a'); // it was only a long pause
        $this->assertTrue($detector->isAlive('node-a'), '...wrongly. Suspicion is never proof.');
    }

    // ---- Phi-accrual failure detection --------------------------------------------------

    /** Let a node report every $intervalMs for a while, so the detector learns its rhythm. */
    private function train(PhiAccrualFailureDetector $detector, FakeClock $clock, string $node, int $intervalMs): void
    {
        for ($i = 0; $i < 20; $i++) {
            $detector->heartbeat($node);
            $clock->advance($intervalMs);
        }

        $detector->heartbeat($node);
    }

    public function test_suspicion_grows_gradually_with_the_length_of_the_silence(): void
    {
        $clock = new FakeClock;
        $detector = new PhiAccrualFailureDetector($clock);
        $this->train($detector, $clock, 'node-a', intervalMs: 1_000);

        $levels = [];

        foreach ([1_000, 4_000, 15_000] as $additionalSilence) {
            $clock->advance($additionalSilence);
            $levels[] = $detector->phi('node-a');
        }

        $this->assertLessThan(1.0, $levels[0], 'One missed interval: barely suspicious.');
        $this->assertGreaterThan($levels[0], $levels[1]);
        $this->assertGreaterThan(8.0, $levels[2], 'Twenty seconds from a node that reports every second: almost certainly gone.');
    }

    public function test_the_same_silence_means_different_things_for_different_nodes(): void
    {
        $clock = new FakeClock;
        $detector = new PhiAccrualFailureDetector($clock, threshold: 8.0);

        // For 100 seconds, "chatty" reports every 100 ms and "quiet" every 10 s.
        for ($elapsed = 0; $elapsed <= 100_000; $elapsed += 100) {
            $detector->heartbeat('chatty');

            if ($elapsed % 10_000 === 0) {
                $detector->heartbeat('quiet');
            }

            $clock->advance(100);
        }

        // Both now go silent for five seconds.
        $clock->advance(5_000);

        $this->assertFalse($detector->isAlive('chatty'), '5 s without a word from a node that reports every 100 ms.');
        $this->assertTrue($detector->isAlive('quiet'), '5 s is nothing for a node that reports every 10 s. One fixed timeout could not get both right.');
    }

    public function test_a_heartbeat_resets_the_suspicion(): void
    {
        $clock = new FakeClock;
        $detector = new PhiAccrualFailureDetector($clock);
        $this->train($detector, $clock, 'node-a', intervalMs: 1_000);

        $clock->advance(30_000);
        $this->assertFalse($detector->isAlive('node-a'));

        $detector->heartbeat('node-a');
        $this->assertSame(0.0, $detector->phi('node-a'));
    }

    // ---- Leases -------------------------------------------------------------------------

    public function test_a_lease_gives_one_holder_exclusive_ownership_for_a_limited_time(): void
    {
        $clock = new FakeClock;
        $leases = new LeaseManager($clock, durationMs: 10_000);

        $this->assertSame(10_000, $leases->acquire('leader', 'node-a'));
        $this->assertNull($leases->acquire('leader', 'node-b'), 'Already leased.');
        $this->assertSame('node-a', $leases->holder('leader'));
    }

    public function test_a_lease_that_is_not_renewed_expires_by_itself(): void
    {
        $clock = new FakeClock;
        $leases = new LeaseManager($clock, durationMs: 10_000);
        $leases->acquire('leader', 'node-a');

        // node-a crashes. Nobody detects anything; time simply passes.
        $clock->advance(10_000);

        $this->assertNull($leases->holder('leader'));
        $this->assertNotNull($leases->acquire('leader', 'node-b'), 'The resource is free without anyone having declared node-a dead.');
    }

    public function test_renewing_keeps_the_lease_indefinitely(): void
    {
        $clock = new FakeClock;
        $leases = new LeaseManager($clock, durationMs: 10_000);
        $leases->acquire('leader', 'node-a');

        for ($i = 0; $i < 10; $i++) {
            $clock->advance(6_000);
            $this->assertNotNull($leases->renew('leader', 'node-a'));
        }

        $this->assertSame('node-a', $leases->holder('leader'), 'A minute later it is still the leader.');
    }

    public function test_an_expired_lease_cannot_be_renewed(): void
    {
        $clock = new FakeClock;
        $leases = new LeaseManager($clock, durationMs: 10_000);
        $leases->acquire('leader', 'node-a');

        $clock->advance(12_000);
        $this->assertNull($leases->renew('leader', 'node-a'), 'Too late: an expired lease is gone, it must be acquired afresh.');

        $leases->acquire('leader', 'node-b');

        $this->assertNull($leases->renew('leader', 'node-a'), 'And by now it belongs to someone else.');
        $this->assertSame('node-b', $leases->holder('leader'));
    }

    public function test_only_the_holder_can_release_a_lease(): void
    {
        $leases = new LeaseManager(new FakeClock);
        $leases->acquire('leader', 'node-a');

        $leases->release('leader', 'node-b');
        $this->assertSame('node-a', $leases->holder('leader'));

        $leases->release('leader', 'node-a');
        $this->assertNull($leases->holder('leader'));
    }

    public function test_a_holder_stops_acting_before_its_lease_officially_ends(): void
    {
        $clock = new FakeClock;
        $leases = new LeaseManager($clock, durationMs: 10_000);
        $leases->acquire('leader', 'node-a');

        $clock->advance(8_000);
        $this->assertTrue($leases->mayAct('leader', 'node-a', safetyMarginMs: 1_000));

        $clock->advance(1_500); // 500 ms of lease left

        $this->assertSame('node-a', $leases->holder('leader'), 'The grantor still considers it the holder...');
        $this->assertFalse($leases->mayAct('leader', 'node-a', safetyMarginMs: 1_000), '...but the holder already stands down, in case its clock is a little slow.');
    }
}
