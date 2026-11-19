<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\FencingTokens\FencedStorage;
use Patterns\Coordination\FencingTokens\Follower;
use Patterns\Coordination\FencingTokens\LockService;
use Patterns\Coordination\FencingTokens\StaleTokenException;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class FencingTokensTest extends TestCase
{
    public function test_every_grant_of_the_lock_comes_with_a_larger_token(): void
    {
        $clock = new FakeClock;
        $lock = new LockService($clock, ttlMs: 10_000);

        $first = $lock->acquire('client-1');
        $this->assertNull($lock->acquire('client-2'), 'Held by client-1.');

        $lock->release('client-1');
        $second = $lock->acquire('client-2');

        $clock->advance(10_001);
        $third = $lock->acquire('client-3');

        $this->assertSame([1, 2, 3], [$first, $second, $third], 'Released or expired, the next holder always gets a higher number.');
    }

    /**
     * The scenario from the README: client 1 takes the lock, stalls past
     * the timeout, and writes after client 2 has taken over.
     *
     * @return array{FencedStorage, bool} the storage, and whether client 1's late write was rejected
     */
    private function pausedClientScenario(bool $enforceTokens): array
    {
        $clock = new FakeClock;
        $lock = new LockService($clock, ttlMs: 10_000);
        $storage = new FencedStorage($enforceTokens);

        $tokenOfClient1 = $lock->acquire('client-1');

        // Client 1 freezes (GC pause, VM migration, slow disk...) for 15 seconds.
        $clock->advance(15_000);

        // The lock expired; client 2 legitimately gets it and does its work.
        $tokenOfClient2 = $lock->acquire('client-2');
        $storage->write('report', 'written by client 2', $tokenOfClient2, 'client-2');

        // Client 1 wakes up. From its point of view no time has passed, so it carries on.
        try {
            $storage->write('report', 'written by client 1', $tokenOfClient1, 'client-1');
            $rejected = false;
        } catch (StaleTokenException) {
            $rejected = true;
        }

        return [$storage, $rejected];
    }

    public function test_without_fencing_a_paused_client_overwrites_the_new_lock_holders_work(): void
    {
        [$storage, $rejected] = $this->pausedClientScenario(enforceTokens: false);

        $this->assertFalse($rejected);
        $this->assertSame(['client-2', 'client-1'], $storage->writeLog, 'Two clients wrote "while holding the lock".');
        $this->assertSame('written by client 1', $storage->read('report'), 'The lock was used correctly by both, and the data is corrupted anyway.');
    }

    public function test_with_fencing_the_storage_rejects_the_stale_lock_holder(): void
    {
        [$storage, $rejected] = $this->pausedClientScenario(enforceTokens: true);

        $this->assertTrue($rejected, 'Token 1 arrived after token 2 had been used.');
        $this->assertSame(['client-2'], $storage->writeLog);
        $this->assertSame('written by client 2', $storage->read('report'));
    }

    public function test_the_current_holder_can_keep_writing(): void
    {
        $lock = new LockService(new FakeClock);
        $storage = new FencedStorage;
        $token = $lock->acquire('client-1');

        $storage->write('a', 1, $token);
        $storage->write('b', 2, $token);

        $this->assertSame(2, $storage->read('b'), 'An equal token is fine: it is the same grant.');
    }

    public function test_the_client_cannot_detect_the_problem_by_itself(): void
    {
        $clock = new FakeClock;
        $lock = new LockService($clock, ttlMs: 10_000);

        $lock->acquire('client-1');
        $stillMine = $lock->holder() === 'client-1'; // client 1 checks: yes, I hold the lock

        $clock->advance(15_000);                     // ...and is then paused before it gets to write
        $lock->acquire('client-2');

        $this->assertTrue($stillMine, 'The check passed.');
        $this->assertSame('client-2', $lock->holder(), 'By the time the write happens it is no longer true. Only the resource can catch this.');
    }

    // ---- Epoch numbers ------------------------------------------------------------------

    public function test_a_follower_ignores_commands_from_a_deposed_leader(): void
    {
        $follower = new Follower;

        $this->assertTrue($follower->receive(leaderEpoch: 1, command: 'set x=1'));

        // A partition isolates leader 1. A new leader is elected with epoch 2.
        $this->assertTrue($follower->receive(leaderEpoch: 2, command: 'set x=2'));

        // The partition heals. The old leader, unaware, sends a command it had queued.
        $this->assertFalse($follower->receive(leaderEpoch: 1, command: 'set x=99'), 'Epoch 1 is history.');

        $this->assertSame(['set x=1', 'set x=2'], $follower->applied);
        $this->assertSame(2, $follower->epoch());
    }

    public function test_the_current_leader_is_followed_for_as_long_as_its_epoch_is_the_newest(): void
    {
        $follower = new Follower;

        foreach (['a', 'b', 'c'] as $command) {
            $this->assertTrue($follower->receive(leaderEpoch: 5, command: $command));
        }

        $this->assertSame(['a', 'b', 'c'], $follower->applied);
    }
}
