<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Patterns\DataConsistency\TransactionalOutbox\InMemoryPublisher;
use Patterns\DataConsistency\TransactionalOutbox\Models\Order;
use Patterns\DataConsistency\TransactionalOutbox\Models\OutboxMessage;
use Patterns\DataConsistency\TransactionalOutbox\OutboxRelay;
use Patterns\DataConsistency\TransactionalOutbox\PlaceOrder;
use RuntimeException;
use Tests\TestCase;

final class TransactionalOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_order_and_its_event_are_committed_together(): void
    {
        $order = (new PlaceOrder)->handle('ada', 4_999);

        $message = OutboxMessage::sole();

        $this->assertSame('order.placed', $message->type);
        $this->assertSame((string) $order->id, $message->aggregate_id);
        $this->assertSame(4_999, $message->payload['total_cents']);
        $this->assertNull($message->published_at, 'Nothing has been sent to the broker yet.');
    }

    public function test_if_the_event_cannot_be_written_the_order_is_rolled_back_too(): void
    {
        OutboxMessage::creating(function (): never {
            throw new RuntimeException('outbox insert failed');
        });

        try {
            (new PlaceOrder)->handle('ada', 4_999);
            $this->fail('The action should have failed.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Order::count(), 'No order without its event.');
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_the_relay_publishes_pending_messages_in_order_and_only_once(): void
    {
        $publisher = new InMemoryPublisher;
        $relay = new OutboxRelay($publisher);

        (new PlaceOrder)->handle('ada', 100);
        (new PlaceOrder)->handle('bob', 200);
        (new PlaceOrder)->handle('cy', 300);

        $this->assertSame(3, $relay->relay());
        $this->assertSame(['ada', 'bob', 'cy'], array_column(array_column($publisher->published, 'payload'), 'customer'));

        $this->assertSame(0, $relay->relay(), 'A second run finds nothing left to send.');
        $this->assertCount(3, $publisher->published);
    }

    public function test_a_broker_outage_keeps_messages_pending_without_reordering(): void
    {
        $publisher = new InMemoryPublisher;
        $relay = new OutboxRelay($publisher);

        (new PlaceOrder)->handle('ada', 100);
        (new PlaceOrder)->handle('bob', 200);

        $publisher->failNext();

        $this->assertSame(0, $relay->relay(), 'The batch stops at the first failure.');
        $this->assertSame(2, OutboxMessage::whereNull('published_at')->count());
        $this->assertSame(1, OutboxMessage::orderBy('id')->first()->attempts);

        $this->assertSame(2, $relay->relay(), 'The broker is back: everything is delivered, still in order.');
        $this->assertSame(['ada', 'bob'], array_column(array_column($publisher->published, 'payload'), 'customer'));
    }

    public function test_a_lost_acknowledgement_causes_a_duplicate_publish(): void
    {
        $publisher = new InMemoryPublisher;
        $relay = new OutboxRelay($publisher);

        (new PlaceOrder)->handle('ada', 100);

        // The broker receives the message but the relay never sees the ack,
        // so the row stays "unpublished"...
        $publisher->loseNextAck();
        $relay->relay();

        // ...and is sent again on the next run.
        $relay->relay();

        $ids = $publisher->publishedIds();

        $this->assertCount(2, $ids, 'At-least-once: the same message went out twice.');
        $this->assertSame($ids[0], $ids[1], 'Same message_id, so an idempotent consumer can drop the duplicate.');
    }
}
