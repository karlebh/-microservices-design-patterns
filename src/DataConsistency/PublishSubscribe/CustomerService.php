<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\PublishSubscribe;

use RuntimeException;

/** The owner of customer data, and the publisher. */
final class CustomerService
{
    public const TOPIC = 'customers';

    /** @var array<int, array{name: string, address: string}> */
    private array $customers = [];

    /** How many times other services had to call us for data. */
    public int $apiCalls = 0;

    /** Simulates an outage of this service's API. */
    public bool $down = false;

    public function __construct(
        private readonly PubSubBroker $broker,
        private readonly EventStyle $style,
    ) {}

    public function register(int $id, string $name, string $address): void
    {
        $this->customers[$id] = ['name' => $name, 'address' => $address];

        $this->publish('customer.registered', $id);
    }

    public function changeAddress(int $id, string $address): void
    {
        $this->customers[$id]['address'] = $address;

        $this->publish('customer.address_changed', $id);
    }

    /** The synchronous API that notification-style consumers depend on. */
    public function getCustomer(int $id): array
    {
        if ($this->down) {
            throw new RuntimeException('Customer service is unavailable.');
        }

        $this->apiCalls++;

        return $this->customers[$id];
    }

    private function publish(string $type, int $id): void
    {
        $event = ['type' => $type, 'customer_id' => $id];

        if ($this->style === EventStyle::StateTransfer) {
            // Carry the state itself, not just a pointer to it.
            $event += $this->customers[$id];
        }

        $this->broker->publish(self::TOPIC, $event);
    }
}
