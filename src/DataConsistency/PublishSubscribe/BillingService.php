<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\PublishSubscribe;

/**
 * Event-CARRIED STATE TRANSFER consumer.
 *
 * Every event contains the customer's current state, which billing stores
 * in its own replica. It never calls the customer service, so it keeps
 * working (with possibly slightly old data) when that service is down.
 */
final class BillingService
{
    /** @var array<int, array{name: string, address: string}> local, read-only copy of customer data */
    private array $customers = [];

    public function __construct(PubSubBroker $broker)
    {
        $broker->subscribe(CustomerService::TOPIC, 'billing', function (array $event) {
            $this->customers[$event['customer_id']] = [
                'name' => $event['name'],
                'address' => $event['address'],
            ];
        });
    }

    /** Answered entirely from the local replica: no network call. */
    public function invoiceAddress(int $customerId): ?string
    {
        $customer = $this->customers[$customerId] ?? null;

        return $customer === null ? null : "{$customer['name']}, {$customer['address']}";
    }
}
