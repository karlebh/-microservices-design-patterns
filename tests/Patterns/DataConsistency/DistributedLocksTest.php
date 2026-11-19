<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Patterns\DataConsistency\DistributedLocks\Chubby\LockService;
use Patterns\DataConsistency\DistributedLocks\Newsletter\NewsLetterMail;
use Patterns\DataConsistency\DistributedLocks\Newsletter\SendNewsletterJob;
use Patterns\DataConsistency\DistributedLocks\Redlock\RedisNode;
use Patterns\DataConsistency\DistributedLocks\Redlock\Redlock;
use Patterns\Support\FakeClock;
use RuntimeException;
use Tests\TestCase;

final class DistributedLocksTest extends TestCase
{
    use RefreshDatabase;

    // ---- Laravel Cache::lock ------------------------------------------------------------

    public function test_only_one_newsletter_send_can_be_in_flight(): void
    {
        Queue::fake(); // the job is queued but has not run yet, so the lock is still held

        $this->postJson('/api/newsletter/send')->assertStatus(202);
        $this->postJson('/api/newsletter/send')->assertStatus(409);

        Queue::assertPushed(SendNewsletterJob::class, 1);
    }

    public function test_the_job_releases_the_lock_when_the_sending_is_done(): void
    {
        Mail::fake();
        User::factory()->count(2)->create();

        // Tests use the sync queue: the job runs inline and releases the lock at the end.
        $this->postJson('/api/newsletter/send')->assertStatus(202);
        Mail::assertSent(NewsLetterMail::class, 2);

        $this->postJson('/api/newsletter/send')->assertStatus(202);
    }

    public function test_the_ttl_frees_a_lock_whose_holder_never_released_it(): void
    {
        Queue::fake(); // simulates a worker that died: nobody will ever release

        $this->postJson('/api/newsletter/send')->assertStatus(202);

        $this->travel(4)->minutes();
        $this->postJson('/api/newsletter/send')->assertStatus(409);

        $this->travel(2)->minutes(); // past the 5 minute TTL
        $this->postJson('/api/newsletter/send')->assertStatus(202);
    }

    public function test_the_newsletter_mail_renders(): void
    {
        $html = (new NewsLetterMail(User::factory()->make(['name' => 'Ada'])))->render();

        $this->assertStringContainsString('Hello Ada!', $html);
    }

    // ---- Redlock ------------------------------------------------------------------------

    /** @return array{Redlock, list<RedisNode>, FakeClock} */
    private function redlock(int $nodeCount = 5): array
    {
        $clock = new FakeClock;
        $nodes = [];

        for ($i = 1; $i <= $nodeCount; $i++) {
            $nodes[] = new RedisNode("redis-{$i}", $clock);
        }

        return [new Redlock($nodes, $clock), $nodes, $clock];
    }

    public function test_redlock_gives_the_lock_to_one_client_at_a_time(): void
    {
        [$redlock] = $this->redlock();

        $lock = $redlock->acquire('invoice:42', 10_000);

        $this->assertNotNull($lock);
        $this->assertNull($redlock->acquire('invoice:42', 10_000), 'Second client is refused.');

        $redlock->release($lock);

        $this->assertNotNull($redlock->acquire('invoice:42', 10_000), 'Free again after release.');
    }

    public function test_redlock_survives_the_loss_of_a_minority_of_nodes(): void
    {
        [$redlock, $nodes] = $this->redlock();
        $nodes[0]->down = true;
        $nodes[1]->down = true;

        $this->assertSame(3, $redlock->quorum());
        $this->assertNotNull($redlock->acquire('invoice:42', 10_000), '3 of 5 is still a majority.');
    }

    public function test_redlock_refuses_without_a_majority_and_cleans_up_after_itself(): void
    {
        [$redlock, $nodes] = $this->redlock();
        $nodes[0]->down = $nodes[1]->down = $nodes[2]->down = true;

        $this->assertNull($redlock->acquire('invoice:42', 10_000), '2 of 5 is not enough.');

        // The two healthy nodes must not be left holding the key, otherwise
        // nobody could lock this resource until the TTL expired.
        $this->assertNull($nodes[3]->get('invoice:42'));
        $this->assertNull($nodes[4]->get('invoice:42'));
    }

    public function test_redlock_only_lets_the_owner_unlock(): void
    {
        [$redlock, $nodes] = $this->redlock();

        $redlock->acquire('invoice:42', 10_000);

        $this->assertFalse($nodes[0]->deleteIfValueMatches('invoice:42', 'someone-elses-token'));
        $this->assertNotNull($nodes[0]->get('invoice:42'), 'Still locked.');
    }

    public function test_redlock_locks_expire_on_their_own(): void
    {
        [$redlock, , $clock] = $this->redlock();

        $redlock->acquire('invoice:42', 10_000); // holder crashes and never releases

        $clock->advance(10_001);

        $this->assertNotNull($redlock->acquire('invoice:42', 10_000));
    }

    public function test_redlock_discards_a_lock_that_took_too_long_to_acquire(): void
    {
        [$redlock, $nodes, $clock] = $this->redlock();

        // Every node takes 300ms to answer: 1500ms in total for a 1000ms lock.
        foreach ($nodes as $node) {
            $node->beforeCommand = fn () => $clock->advance(300);
        }

        $this->assertNull(
            $redlock->acquire('invoice:42', 1_000),
            'By the time a majority answered, the lock had no validity left.'
        );
    }

    public function test_redlock_reports_how_long_the_lock_can_be_trusted(): void
    {
        [$redlock, $nodes, $clock] = $this->redlock();
        $nodes[0]->beforeCommand = fn () => $clock->advance(200);

        $lock = $redlock->acquire('invoice:42', 10_000);

        // 10000 TTL - 200 spent acquiring - (1% drift + 2ms) = 9698
        $this->assertSame(9_698, $lock->validityMs);
    }

    // ---- Chubby-style lock service ------------------------------------------------------

    public function test_a_lock_service_hands_out_increasing_sequencers(): void
    {
        $service = new LockService(new FakeClock);
        $alice = $service->openSession();
        $bob = $service->openSession();

        $first = $service->tryAcquire($alice, 'leader');
        $this->assertSame(1, $first->generation);
        $this->assertNull($service->tryAcquire($bob, 'leader'), 'Held by alice.');

        $service->release($alice, 'leader');

        $second = $service->tryAcquire($bob, 'leader');
        $this->assertSame(2, $second->generation, 'Each new holder gets a higher generation.');

        $this->assertFalse($service->checkSequencer($first), 'The old proof is no longer valid.');
        $this->assertTrue($service->checkSequencer($second));
    }

    public function test_a_session_that_stops_heartbeating_loses_its_locks(): void
    {
        $clock = new FakeClock;
        $service = new LockService($clock, leaseMs: 12_000);
        $alice = $service->openSession();
        $bob = $service->openSession();

        $sequencer = $service->tryAcquire($alice, 'leader');

        // Bob keeps heartbeating; Alice has crashed.
        $clock->advance(10_000);
        $service->keepAlive($bob);
        $clock->advance(3_000);

        $this->assertNull($service->holder('leader'), "Alice's lease ran out, so her lock was released.");
        $this->assertFalse($service->checkSequencer($sequencer));
        $this->assertSame(2, $service->tryAcquire($bob, 'leader')->generation);

        $this->expectException(RuntimeException::class);
        $service->keepAlive($alice); // too late: the session is gone
    }

    public function test_heartbeats_keep_a_session_and_its_locks_alive(): void
    {
        $clock = new FakeClock;
        $service = new LockService($clock, leaseMs: 12_000);
        $alice = $service->openSession();
        $service->tryAcquire($alice, 'leader');

        for ($i = 0; $i < 10; $i++) {
            $clock->advance(8_000);
            $service->keepAlive($alice);
        }

        $this->assertSame($alice, $service->holder('leader'), 'Still held after 80 seconds.');
    }
}
