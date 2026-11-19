<?php

declare(strict_types=1);

namespace Tests\Patterns\Messaging;

use Patterns\Messaging\MessageOrdering\PartitionedTopic;
use Patterns\Messaging\MessageOrdering\Resequencer;
use Patterns\Messaging\MessageOrdering\SequenceStamper;
use PHPUnit\Framework\TestCase;

final class MessageOrderingTest extends TestCase
{
    /** The events of three accounts, interleaved as they happened. */
    private const PUBLISHED = [
        ['ada', 'open'], ['bob', 'open'], ['ada', 'deposit'], ['cy', 'open'],
        ['bob', 'deposit'], ['ada', 'withdraw'], ['cy', 'deposit'], ['bob', 'close'], ['ada', 'close'],
    ];

    private function topic(int $partitions): PartitionedTopic
    {
        $topic = new PartitionedTopic($partitions);

        foreach (self::PUBLISHED as [$key, $payload]) {
            $topic->publish($key, $payload);
        }

        return $topic;
    }

    /** @return array<string, list<string>> key => payloads in the order they were consumed */
    private function perKey(array $consumed): array
    {
        $byKey = [];

        foreach ($consumed as $message) {
            $byKey[$message['key']][] = $message['payload'];
        }

        ksort($byKey);

        return $byKey;
    }

    // ---- Per-key vs global --------------------------------------------------------------

    public function test_per_key_order_survives_any_interleaving_of_partitions(): void
    {
        $topic = $this->topic(partitions: 3);

        $expected = [
            'ada' => ['open', 'deposit', 'withdraw', 'close'],
            'bob' => ['open', 'deposit', 'close'],
            'cy' => ['open', 'deposit'],
        ];

        // Whatever order the partitions are drained in, each account's story is intact.
        foreach ([[0, 1, 2], [2, 1, 0], [1, 2, 0]] as $partitionOrder) {
            $this->assertSame($expected, $this->perKey($topic->consume($partitionOrder)));
        }
    }

    public function test_all_messages_of_a_key_live_in_a_single_partition(): void
    {
        $topic = $this->topic(partitions: 3);

        $partition = $topic->partition($topic->partitionOf('ada'));
        $adaEvents = array_values(array_filter($partition, fn (array $m) => $m['key'] === 'ada'));

        $this->assertCount(4, $adaEvents);
    }

    public function test_multiple_partitions_do_not_preserve_the_global_order(): void
    {
        // Keys chosen so they do not all hash to the same partition.
        $topic = new PartitionedTopic(2);
        $keys = ['a', 'b', 'c', 'd', 'e', 'f'];

        foreach ($keys as $key) {
            $topic->publish($key, 'event');
        }

        $this->assertNotSame([], $topic->partition(0), 'Sanity check: both partitions are in use.');
        $this->assertNotSame([], $topic->partition(1));

        $oneWay = array_column($topic->consume([0, 1]), 'key');
        $otherWay = array_column($topic->consume([1, 0]), 'key');

        $this->assertNotSame($oneWay, $otherWay, 'What consumers see depends on how the partitions happen to be drained...');
        $this->assertTrue($oneWay !== $keys || $otherWay !== $keys, '...so the publish order across keys cannot be relied on.');
        $this->assertEqualsCanonicalizing($keys, $oneWay, 'Nothing is lost, only reordered.');
    }

    public function test_a_single_partition_gives_a_total_order(): void
    {
        $topic = $this->topic(partitions: 1);

        $this->assertSame(
            array_column(self::PUBLISHED, 1),
            array_column($topic->consume([0]), 'payload'),
            'Exactly the publish order, at the price of having one consumer.'
        );
    }

    // ---- Resequencing on the consumer side ----------------------------------------------

    public function test_sequence_numbers_count_up_independently_for_each_key(): void
    {
        $stamper = new SequenceStamper;

        $this->assertSame([1, 2, 1, 3], [
            $stamper->next('ada'), $stamper->next('ada'), $stamper->next('bob'), $stamper->next('ada'),
        ]);
    }

    public function test_the_resequencer_restores_order(): void
    {
        $delivered = [];
        $resequencer = new Resequencer(function (string $key, int $sequence, string $payload) use (&$delivered) {
            $delivered[] = $payload;
        });

        // Arrival order: 3, 1, 2.
        $resequencer->accept('ada', 3, 'withdraw');
        $this->assertSame([], $delivered, 'Sequence 3 has to wait.');
        $this->assertSame(1, $resequencer->waitingFor('ada'));

        $resequencer->accept('ada', 1, 'open');
        $this->assertSame(['open'], $delivered, '1 goes out; 3 still waits for 2.');
        $this->assertSame(2, $resequencer->waitingFor('ada'));

        $resequencer->accept('ada', 2, 'deposit');
        $this->assertSame(['open', 'deposit', 'withdraw'], $delivered, 'The gap closed, so 2 AND the buffered 3 are released.');
        $this->assertNull($resequencer->waitingFor('ada'));
    }

    public function test_the_resequencer_drops_duplicates(): void
    {
        $delivered = [];
        $resequencer = new Resequencer(function (string $key, int $sequence, string $payload) use (&$delivered) {
            $delivered[] = $payload;
        });

        $resequencer->accept('ada', 1, 'open');
        $resequencer->accept('ada', 1, 'open');      // redelivered after it was processed
        $resequencer->accept('ada', 3, 'withdraw');
        $resequencer->accept('ada', 3, 'withdraw');  // redelivered while still buffered
        $resequencer->accept('ada', 2, 'deposit');

        $this->assertSame(['open', 'deposit', 'withdraw'], $delivered);
        $this->assertSame(2, $resequencer->duplicatesDropped);
    }

    public function test_a_gap_only_blocks_its_own_key(): void
    {
        $delivered = [];
        $resequencer = new Resequencer(function (string $key, int $sequence, string $payload) use (&$delivered) {
            $delivered[] = "{$key}:{$payload}";
        });

        $resequencer->accept('ada', 2, 'deposit'); // ada's #1 is missing
        $resequencer->accept('bob', 1, 'open');
        $resequencer->accept('bob', 2, 'deposit');

        $this->assertSame(['bob:open', 'bob:deposit'], $delivered, 'Bob is unaffected by the hole in Ada\'s stream.');
        $this->assertSame(1, $resequencer->buffered('ada'));
    }
}
