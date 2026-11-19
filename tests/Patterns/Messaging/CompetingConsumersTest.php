<?php

declare(strict_types=1);

namespace Tests\Patterns\Messaging;

use Patterns\Messaging\CompetingConsumers\PartitionedConsumerGroup;
use Patterns\Messaging\CompetingConsumers\WorkQueue;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class CompetingConsumersTest extends TestCase
{
    // ---- Competing consumers ------------------------------------------------------------

    public function test_workers_share_one_queue_and_never_get_the_same_message(): void
    {
        $queue = new WorkQueue(new FakeClock);

        foreach (range(1, 4) as $i) {
            $queue->enqueue("job {$i}");
        }

        // Three workers ask for work at the same moment.
        $claimed = [$queue->claim(), $queue->claim(), $queue->claim()];

        $this->assertSame(['job 1', 'job 2', 'job 3'], array_column($claimed, 'payload'));

        foreach ($claimed as $job) {
            $queue->complete($job['id']);
        }

        $this->assertSame(1, $queue->size(), 'Completed messages are gone; job 4 is still waiting.');
    }

    public function test_a_message_whose_worker_died_becomes_visible_again(): void
    {
        $clock = new FakeClock;
        $queue = new WorkQueue($clock, visibilityTimeoutMs: 30_000);
        $queue->enqueue('send invoice');

        $first = $queue->claim(); // this worker crashes and never completes the message

        $clock->advance(29_000);
        $this->assertNull($queue->claim(), 'Still hidden: the queue assumes the first worker is busy.');

        $clock->advance(2_000);
        $retry = $queue->claim();

        $this->assertSame('send invoice', $retry['payload']);
        $this->assertSame(2, $retry['receiveCount'], 'The receive count shows this is a redelivery.');
        $this->assertSame($first['id'], $retry['id']);
    }

    public function test_a_heartbeat_keeps_a_long_job_from_being_handed_to_someone_else(): void
    {
        $clock = new FakeClock;
        $queue = new WorkQueue($clock, visibilityTimeoutMs: 30_000);
        $queue->enqueue('encode video');

        $job = $queue->claim();

        for ($i = 0; $i < 5; $i++) {
            $clock->advance(20_000);
            $queue->heartbeat($job['id']);
        }

        $this->assertNull($queue->claim(), '100 seconds in, the job is still exclusively ours.');
    }

    public function test_without_a_heartbeat_a_slow_job_is_processed_twice(): void
    {
        $clock = new FakeClock;
        $queue = new WorkQueue($clock, visibilityTimeoutMs: 30_000);
        $queue->enqueue('charge card');

        $slowWorker = $queue->claim();
        $clock->advance(31_000); // the slow worker is alive, just taking longer than the timeout

        $secondWorker = $queue->claim();

        $this->assertSame($slowWorker['id'], $secondWorker['id'], 'Two workers now hold the same message: handlers must be idempotent.');
    }

    public function test_competing_consumers_give_up_ordering(): void
    {
        $queue = new WorkQueue(new FakeClock);
        $queue->enqueue('deposit 100');
        $queue->enqueue('withdraw 100');

        $workerA = $queue->claim(); // deposit
        $workerB = $queue->claim(); // withdraw

        // Worker B happens to be faster.
        $applied = [$workerB['payload'], $workerA['payload']];

        $this->assertSame(['withdraw 100', 'deposit 100'], $applied, 'The withdrawal ran before the deposit it depended on.');
    }

    // ---- Partitioned processing ---------------------------------------------------------

    public function test_messages_for_one_key_are_handled_by_one_consumer_in_order(): void
    {
        $group = new PartitionedConsumerGroup(partitionCount: 4);
        $group->addConsumer('c1');
        $group->addConsumer('c2');

        $group->publish('account-1', 'deposit 100');
        $group->publish('account-2', 'deposit 5');
        $group->publish('account-1', 'withdraw 100');
        $group->publish('account-1', 'close');

        $seen = [];

        foreach (['c2', 'c1'] as $consumer) { // poll order is irrelevant
            foreach ($group->poll($consumer) as $message) {
                $seen[$message['key']]['consumers'][$consumer] = true;
                $seen[$message['key']]['payloads'][] = $message['payload'];
            }
        }

        $this->assertCount(1, $seen['account-1']['consumers'], 'One consumer owns the whole account.');
        $this->assertSame(['deposit 100', 'withdraw 100', 'close'], $seen['account-1']['payloads']);
    }

    public function test_every_partition_has_exactly_one_owner(): void
    {
        $group = new PartitionedConsumerGroup(partitionCount: 6);
        $group->addConsumer('c1');
        $group->addConsumer('c2');
        $group->addConsumer('c3');

        $this->assertSame([0, 3], $group->partitionsOf('c1'));
        $this->assertSame([1, 4], $group->partitionsOf('c2'));
        $this->assertSame([2, 5], $group->partitionsOf('c3'));
    }

    public function test_parallelism_is_capped_by_the_number_of_partitions(): void
    {
        $group = new PartitionedConsumerGroup(partitionCount: 2);

        foreach (['c1', 'c2', 'c3', 'c4'] as $consumer) {
            $group->addConsumer($consumer);
        }

        $this->assertSame([0], $group->partitionsOf('c1'));
        $this->assertSame([1], $group->partitionsOf('c2'));
        $this->assertSame([], $group->partitionsOf('c3'), 'Extra consumers have nothing to do.');
        $this->assertSame([], $group->partitionsOf('c4'));
    }

    public function test_partitions_are_reassigned_when_a_consumer_leaves(): void
    {
        $group = new PartitionedConsumerGroup(partitionCount: 4);
        $group->addConsumer('c1');
        $group->addConsumer('c2');
        $group->publish('account-1', 'deposit 100');

        $group->removeConsumer('c2');

        $this->assertSame([0, 1, 2, 3], $group->partitionsOf('c1'));
        $this->assertCount(1, $group->poll('c1'), 'Nothing is orphaned: the survivor reads every partition.');
    }

    public function test_a_hot_key_overloads_a_single_partition(): void
    {
        $group = new PartitionedConsumerGroup(partitionCount: 4);

        foreach (range(1, 20) as $i) {
            $group->publish('celebrity-account', "event {$i}");
        }

        $backlog = $group->backlog();

        $this->assertSame(20, $backlog[$group->partitionOf('celebrity-account')]);
        $this->assertCount(3, array_keys($backlog, 0, true), 'The other partitions are idle while one is swamped: adding consumers will not help.');
    }
}
