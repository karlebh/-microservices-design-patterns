<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Patterns\DataArchitecture\ReadReplicas\Models\Task;
use Patterns\DataArchitecture\ReadReplicas\Simulation\Primary;
use Patterns\DataArchitecture\ReadReplicas\Simulation\ReadConsistency;
use Patterns\DataArchitecture\ReadReplicas\Simulation\Replica;
use Patterns\DataArchitecture\ReadReplicas\Simulation\ReplicaRouter;
use Patterns\DataArchitecture\ReadReplicas\Simulation\TaskStatsProjection;
use Tests\TestCase;

final class ReadReplicasTest extends TestCase
{
    use RefreshDatabase;

    // ---- The Laravel side: the split is configuration, not code -------------------------

    public function test_the_database_connections_declare_separate_read_and_write_hosts(): void
    {
        foreach (['pgsql', 'mysql'] as $connection) {
            $config = config("database.connections.{$connection}");

            $this->assertCount(2, $config['read']['host'], 'Two replicas share the SELECTs.');
            $this->assertCount(1, $config['write']['host'], 'One primary takes every write.');
            $this->assertTrue($config['sticky'], 'A request that wrote reads its own write from the primary.');
        }
    }

    public function test_the_task_api_works_end_to_end(): void
    {
        $id = $this->postJson('/api/tasks', ['title' => 'Write the tests'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Write the tests')
            ->json('data.id');

        $this->getJson('/api/tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/tasks/{$id}")->assertOk()->assertJsonPath('data.id', $id);

        $this->putJson("/api/tasks/{$id}", ['completed' => true])->assertOk()->assertJsonPath('data.completed', true);
        $this->assertTrue(Task::find($id)->completed);

        $this->deleteJson("/api/tasks/{$id}")->assertOk();
        $this->assertSame(0, Task::count());
    }

    public function test_the_task_factory_is_found_next_to_the_model(): void
    {
        $this->assertCount(3, Task::factory()->count(3)->create());
    }

    // ---- The simulation: what replication lag does and how to live with it ---------------

    /** @return array{Primary, Replica, Replica} */
    private function cluster(): array
    {
        $primary = new Primary;

        return [$primary, new Replica('replica-1', $primary), new Replica('replica-2', $primary)];
    }

    public function test_a_replica_serves_stale_data_until_it_catches_up(): void
    {
        [$primary, $replica] = $this->cluster();

        $primary->write('task:1', ['title' => 'Buy milk', 'completed' => false]);

        $this->assertNull($replica->read('task:1'), 'The write has not been replicated yet.');
        $this->assertSame(1, $replica->lag());

        $replica->catchUp();

        $this->assertSame('Buy milk', $replica->read('task:1')['title']);
        $this->assertSame(0, $replica->lag());
    }

    public function test_replicas_can_lag_by_different_amounts(): void
    {
        [$primary, $fast, $slow] = $this->cluster();

        foreach (range(1, 5) as $i) {
            $primary->write("task:{$i}", ['title' => "Task {$i}", 'completed' => false]);
        }

        $fast->catchUp();
        $slow->catchUp(maxChanges: 2);

        $this->assertSame(0, $fast->lag());
        $this->assertSame(3, $slow->lag());
        $this->assertNotNull($fast->read('task:5'));
        $this->assertNull($slow->read('task:5'), 'Two replicas can disagree at the same instant.');
    }

    public function test_eventual_consistency_can_lose_your_own_write(): void
    {
        [$primary, $r1, $r2] = $this->cluster();
        $router = new ReplicaRouter($primary, [$r1, $r2], ReadConsistency::Eventual);

        $router->write('task:1', ['title' => 'Buy milk', 'completed' => false]);

        $this->assertNull($router->read('task:1'), 'The classic "I just saved it, where is it?" bug.');
        $this->assertSame(['replica-1'], $router->readsServedBy);
    }

    public function test_sticky_primary_lets_a_request_read_its_own_write(): void
    {
        [$primary, $r1, $r2] = $this->cluster();
        $router = new ReplicaRouter($primary, [$r1, $r2], ReadConsistency::StickyPrimary);

        $router->read('task:1');
        $router->write('task:1', ['title' => 'Buy milk', 'completed' => false]);

        $this->assertSame('Buy milk', $router->read('task:1')['title']);
        $this->assertSame(['replica-1', 'primary'], $router->readsServedBy, 'Reads moved to the primary after the write.');

        // Stickiness lasts one request. The next one is back on (possibly stale) replicas.
        $router->beginRequest();
        $this->assertNull($router->read('task:1'));
    }

    public function test_an_lsn_token_gives_read_your_writes_without_pinning_to_the_primary(): void
    {
        [$primary, $r1, $r2] = $this->cluster();
        $router = new ReplicaRouter($primary, [$r1, $r2], ReadConsistency::ReadYourWrites);

        $router->write('task:1', ['title' => 'Buy milk', 'completed' => false]);

        // No replica has the write yet: fall back to the primary.
        $this->assertSame('Buy milk', $router->read('task:1')['title']);

        // replica-2 catches up; replica-1 is still behind and is skipped.
        $r2->catchUp();
        $router->beginRequest();
        $this->assertSame('Buy milk', $router->read('task:1')['title']);

        $this->assertSame(['primary', 'replica-2'], $router->readsServedBy);
    }

    public function test_a_projection_is_kept_in_sync_from_the_same_replication_log(): void
    {
        [$primary] = $this->cluster();
        $stats = new TaskStatsProjection($primary);

        $primary->write('task:1', ['title' => 'A', 'completed' => false]);
        $primary->write('task:2', ['title' => 'B', 'completed' => false]);
        $primary->write('task:3', ['title' => 'C', 'completed' => true]);

        $this->assertSame(['open' => 0, 'done' => 0], $stats->stats(), 'Eventually consistent, like a replica.');

        $stats->sync();
        $this->assertSame(['open' => 2, 'done' => 1], $stats->stats());

        $primary->write('task:1', ['title' => 'A', 'completed' => true]); // completed
        $primary->delete('task:2');                                        // removed
        $stats->sync();

        $this->assertSame(['open' => 0, 'done' => 2], $stats->stats());
    }
}
