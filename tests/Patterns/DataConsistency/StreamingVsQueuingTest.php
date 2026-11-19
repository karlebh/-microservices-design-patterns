<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use OutOfRangeException;
use Patterns\DataConsistency\StreamingVsQueuing\EventStream;
use Patterns\DataConsistency\StreamingVsQueuing\MessageQueue;
use PHPUnit\Framework\TestCase;

final class StreamingVsQueuingTest extends TestCase
{
    // ---- Queue --------------------------------------------------------------------------

    public function test_a_queue_gives_each_message_to_exactly_one_consumer(): void
    {
        $queue = new MessageQueue;
        $queue->send('resize image 1');
        $queue->send('resize image 2');

        $workerA = $queue->receive();
        $workerB = $queue->receive();

        $this->assertSame('resize image 1', $workerA['payload']);
        $this->assertSame('resize image 2', $workerB['payload'], 'Workers compete; they never get the same job.');
        $this->assertNull($queue->receive(), 'Nothing left to hand out.');
    }

    public function test_an_acknowledged_message_is_deleted_and_cannot_be_read_again(): void
    {
        $queue = new MessageQueue;
        $queue->send('send welcome email');

        $queue->ack($queue->receive()['id']);

        $this->assertSame(0, $queue->size());
        $this->assertNull($queue->receive(), 'A consumer that joins now sees nothing: there is no history.');
    }

    public function test_a_rejected_message_goes_back_for_another_consumer(): void
    {
        $queue = new MessageQueue;
        $queue->send('charge card');
        $queue->send('send receipt');

        $first = $queue->receive();
        $queue->nack($first['id']); // this worker could not process it

        $this->assertSame('charge card', $queue->receive()['payload'], 'Redelivered, ahead of newer messages.');
        $this->assertSame(2, $queue->size());
    }

    // ---- Stream -------------------------------------------------------------------------

    public function test_every_consumer_group_reads_the_whole_stream_independently(): void
    {
        $stream = new EventStream;
        $stream->append('order.placed #1');
        $stream->append('order.placed #2');

        $this->assertSame(['order.placed #1', 'order.placed #2'], $stream->read('analytics'));
        $this->assertSame(['order.placed #1', 'order.placed #2'], $stream->read('emails'), 'Reading by one group takes nothing away from another.');

        $this->assertSame(2, $stream->size(), 'The events are still there.');
    }

    public function test_a_group_only_tracks_how_far_it_has_read(): void
    {
        $stream = new EventStream;

        foreach (range(1, 5) as $i) {
            $stream->append("event {$i}");
        }

        $this->assertSame(['event 1', 'event 2'], $stream->read('analytics', max: 2));
        $this->assertSame(3, $stream->lag('analytics'));

        $this->assertSame(['event 3', 'event 4', 'event 5'], $stream->read('analytics'));
        $this->assertSame([], $stream->read('analytics'), 'Caught up.');
    }

    public function test_a_stream_can_be_replayed_from_any_offset(): void
    {
        $stream = new EventStream;
        $stream->append('a');
        $stream->append('b');
        $stream->append('c');

        $stream->read('search-index');

        // A bug was fixed in the indexer: reprocess everything.
        $stream->seek('search-index', 0);

        $this->assertSame(['a', 'b', 'c'], $stream->read('search-index'));
    }

    public function test_a_consumer_created_later_can_still_read_history(): void
    {
        $stream = new EventStream;
        $stream->append('a');
        $stream->append('b');

        // This group did not exist when the events were written.
        $this->assertSame(['a', 'b'], $stream->read('brand-new-service'));
    }

    public function test_retention_removes_old_events_no_matter_who_has_read_them(): void
    {
        $stream = new EventStream;

        foreach (range(0, 4) as $i) {
            $stream->append("event {$i}");
        }

        $stream->read('slow-consumer', max: 1); // now at offset 1

        $stream->truncateBefore(3);

        $this->assertSame(3, $stream->earliestOffset());
        $this->assertSame(['event 3', 'event 4'], $stream->read('new-consumer'), 'New groups start at the oldest event still kept.');

        $this->expectException(OutOfRangeException::class);
        $stream->read('slow-consumer'); // it fell off the end of the log
    }
}
