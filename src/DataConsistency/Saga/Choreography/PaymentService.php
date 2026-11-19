<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Saga\Choreography;

/**
 * Charges once stock is secured. On failure it just says so; it has no idea
 * that someone else will react by releasing stock.
 */
final class PaymentService
{
    /** @var array<string, int> order id => amount charged */
    public array $charges = [];

    public function __construct(private readonly EventBus $bus, private readonly int $creditLimit)
    {
        $bus->subscribe('inventory.reserved', $this->charge(...));
    }

    private function charge(array $order): void
    {
        if ($order['amount'] > $this->creditLimit) {
            $this->bus->publish('payment.failed', ['order_id' => $order['order_id'], 'reason' => 'card declined']);

            return;
        }

        // Local transaction #3.
        $this->charges[$order['order_id']] = $order['amount'];

        $this->bus->publish('payment.charged', ['order_id' => $order['order_id']]);
    }
}
