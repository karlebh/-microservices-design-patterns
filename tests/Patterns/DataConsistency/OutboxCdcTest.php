<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use LogicException;
use Patterns\DataArchitecture\ChangeDataCapture\CdcConnector;
use Patterns\DataArchitecture\ChangeDataCapture\Database;
use Patterns\DataConsistency\OutboxCdc\OrderService;
use Patterns\DataConsistency\OutboxCdc\OutboxEventRouter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutboxCdcTest extends TestCase
{
    /** @var array<string, list<array>> topic => messages, standing in for the broker */
    private array $topics = [];

    private function connector(Database $database, int $offset = 0): CdcConnector
    {
        $connector = new CdcConnector($database, $offset);

        $connector->addSink((new OutboxEventRouter(function (string $topic, array $message) {
            $this->topics[$topic][] = $message;
        }))(...));

        return $connector;
    }

    public function test_events_reach_the_broker_from_the_transaction_log_without_polling_the_table(): void
    {
        $database = new Database;
        $orders = new OrderService($database);
        $connector = $this->connector($database);

        $orders->placeOrder('order-1', 'ada', 4_999);
        $orders->placeOrder('order-2', 'bob', 1_500);
        $orders->cancelOrder('order-1');

        $connector->poll();

        $this->assertSame(
            ['order.placed', 'order.placed', 'order.cancelled'],
            array_column($this->topics['order.events'], 'type'),
            'In commit order, routed to a topic named after the aggregate type.'
        );
        $this->assertSame(['order-1', 'order-2', 'order-1'], array_column($this->topics['order.events'], 'key'));
        $this->assertSame(4_999, $this->topics['order.events'][0]['payload']['total_cents']);
    }

    public function test_the_outbox_table_stays_empty(): void
    {
        $database = new Database;
        $orders = new OrderService($database);

        $orders->placeOrder('order-1', 'ada', 4_999);

        $this->assertSame([], $database->rows('outbox'), 'Inserted and deleted in the same transaction...');

        $this->connector($database)->poll();

        $this->assertCount(1, $this->topics['order.events'], '...yet the event was captured from the log.');
    }

    public function test_the_order_and_its_event_are_atomic(): void
    {
        $database = new Database;
        $orders = new OrderService($database);

        $orders->placeOrder('order-1', 'ada', 4_999);

        try {
            $orders->placeOrder('order-1', 'ada', 4_999); // duplicate key: the transaction fails
            $this->fail('Expected the duplicate order to be rejected.');
        } catch (LogicException) {
            // rolled back
        }

        $this->connector($database)->poll();

        $this->assertCount(1, $this->topics['order.events'], 'No event for the order that was never committed.');
    }

    public function test_only_outbox_inserts_become_messages(): void
    {
        $database = new Database;
        (new OrderService($database))->placeOrder('order-1', 'ada', 4_999);

        // The transaction logged three changes: orders insert, outbox insert, outbox delete.
        $this->assertCount(3, $database->logAfter(0));

        $this->connector($database)->poll();

        $this->assertSame(['order.events'], array_keys($this->topics));
        $this->assertCount(1, $this->topics['order.events'], 'Business tables are not published; the outbox is the contract.');
    }

    public function test_a_restarted_connector_continues_from_its_log_offset(): void
    {
        $database = new Database;
        $orders = new OrderService($database);

        $connector = $this->connector($database);
        $orders->placeOrder('order-1', 'ada', 100);
        $connector->poll();

        $orders->placeOrder('order-2', 'bob', 200); // written while the connector is down

        $this->connector($database, $connector->offset())->poll();

        $this->assertSame(['evt-1', 'evt-2'], array_column($this->topics['order.events'], 'id'), 'Nothing lost, nothing repeated.');
    }

    public function test_a_crash_before_the_offset_is_saved_republishes_with_the_same_id(): void
    {
        $database = new Database;
        (new OrderService($database))->placeOrder('order-1', 'ada', 100);

        $published = [];
        $crashOnce = true;

        $connector = new CdcConnector($database);
        $connector->addSink((new OutboxEventRouter(function (string $topic, array $message) use (&$published, &$crashOnce) {
            $published[] = $message['id'];

            if ($crashOnce) {
                $crashOnce = false;

                throw new RuntimeException('connector died right after the broker accepted the message');
            }
        }))(...));

        try {
            $connector->poll();
        } catch (RuntimeException) {
            // restarted below
        }

        $connector->poll();

        $this->assertSame(['evt-1', 'evt-1'], $published, 'At-least-once. The stable id lets consumers drop the duplicate.');
    }
}
