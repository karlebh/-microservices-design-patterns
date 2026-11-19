<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\PublishSubscribe;

/**
 * Event NOTIFICATION consumer.
 *
 * The event only says "customer 42 changed". To learn the new address,
 * shipping calls the customer service — so it needs that service to be up
 * at the moment the event is handled.
 */
final class ShippingService
{
    /** @var array<int, string> customer id => label to print on parcels */
    public array $labels = [];

    public function __construct(PubSubBroker $broker, private readonly CustomerService $customers)
    {
        $broker->subscribe(CustomerService::TOPIC, 'shipping', function (array $event) {
            $customer = $this->customers->getCustomer($event['customer_id']); // the callback

            $this->labels[$event['customer_id']] = "{$customer['name']}, {$customer['address']}";
        });
    }
}
