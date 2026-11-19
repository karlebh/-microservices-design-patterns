<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use Patterns\DataConsistency\PublishSubscribe\BillingService;
use Patterns\DataConsistency\PublishSubscribe\CustomerService;
use Patterns\DataConsistency\PublishSubscribe\EventStyle;
use Patterns\DataConsistency\PublishSubscribe\PubSubBroker;
use Patterns\DataConsistency\PublishSubscribe\ShippingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PublishSubscribeTest extends TestCase
{
    public function test_every_subscriber_of_a_topic_gets_its_own_copy(): void
    {
        $broker = new PubSubBroker;
        $received = [];

        foreach (['audit', 'search', 'email'] as $subscriber) {
            $broker->subscribe('orders', $subscriber, function (array $event) use (&$received, $subscriber) {
                $received[$subscriber][] = $event['id'];
            });
        }

        $this->assertSame(3, $broker->publish('orders', ['id' => 1]));
        $this->assertSame(['audit' => [1], 'search' => [1], 'email' => [1]], $received);

        $this->assertSame(0, $broker->publish('nobody-listens', ['id' => 2]), 'Publishing never fails for lack of listeners.');
    }

    public function test_a_failing_subscriber_does_not_affect_the_others(): void
    {
        $broker = new PubSubBroker;
        $delivered = false;

        $broker->subscribe('orders', 'broken', fn () => throw new RuntimeException('subscriber bug'));
        $broker->subscribe('orders', 'healthy', function () use (&$delivered) {
            $delivered = true;
        });

        $this->assertSame(1, $broker->publish('orders', ['id' => 1]));
        $this->assertTrue($delivered);
        $this->assertSame('broken', $broker->failedDeliveries[0]['subscriber']);
    }

    public function test_notification_consumers_call_back_for_the_details(): void
    {
        $broker = new PubSubBroker;
        $customers = new CustomerService($broker, EventStyle::Notification);
        $shipping = new ShippingService($broker, $customers);

        $customers->register(42, 'Ada', '1 Main St');
        $customers->changeAddress(42, '9 Side Rd');

        $this->assertSame('Ada, 9 Side Rd', $shipping->labels[42]);
        $this->assertSame(2, $customers->apiCalls, 'One callback per event.');
    }

    public function test_notification_consumers_depend_on_the_source_being_available(): void
    {
        $broker = new PubSubBroker;
        $customers = new CustomerService($broker, EventStyle::Notification);
        $shipping = new ShippingService($broker, $customers);

        $customers->register(42, 'Ada', '1 Main St');

        $customers->down = true; // the API is down, though the change itself is still published
        $customers->changeAddress(42, '9 Side Rd');

        $this->assertSame('Ada, 1 Main St', $shipping->labels[42], 'Shipping is stuck on the old address.');
        $this->assertCount(1, $broker->failedDeliveries);
    }

    public function test_state_transfer_consumers_never_call_back_and_survive_an_outage(): void
    {
        $broker = new PubSubBroker;
        $customers = new CustomerService($broker, EventStyle::StateTransfer);
        $billing = new BillingService($broker);

        $customers->register(42, 'Ada', '1 Main St');

        $customers->down = true;
        $customers->changeAddress(42, '9 Side Rd');

        $this->assertSame('Ada, 9 Side Rd', $billing->invoiceAddress(42), 'Up to date, from its own replica.');
        $this->assertSame(0, $customers->apiCalls, 'The source was never queried.');
        $this->assertSame([], $broker->failedDeliveries);
    }

    public function test_a_replica_only_knows_what_it_was_told(): void
    {
        $broker = new PubSubBroker;
        $customers = new CustomerService($broker, EventStyle::StateTransfer);

        $customers->register(1, 'Early Bird', 'Old Town'); // published before billing existed

        $billing = new BillingService($broker);
        $customers->register(2, 'Ada', '1 Main St');

        $this->assertNull($billing->invoiceAddress(1), 'A late subscriber needs a backfill or a replayable log.');
        $this->assertSame('Ada, 1 Main St', $billing->invoiceAddress(2));
    }
}
