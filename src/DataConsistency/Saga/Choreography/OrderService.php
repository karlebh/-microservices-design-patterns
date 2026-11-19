<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Saga\Choreography;

/**
 * Starts the saga by announcing a new order, then waits to hear how it went.
 * It does not call inventory or payment. It doesn't even know they exist.
 */
final class OrderService
{
    /** @var array<string, string> order id => pending | confirmed | cancelled */
    public array $orders = [];

    public function __construct(private readonly EventBus $bus)
    {
        $bus->subscribe('payment.charged', fn (array $e) => $this->orders[$e['order_id']] = 'confirmed');

        // Either failure ends the saga for this order.
        $bus->subscribe('inventory.rejected', fn (array $e) => $this->orders[$e['order_id']] = 'cancelled');
        $bus->subscribe('payment.failed', fn (array $e) => $this->orders[$e['order_id']] = 'cancelled');
    }

    public function placeOrder(string $orderId, string $sku, int $quantity, int $amount): void
    {
        // Local transaction #1: the order exists, but only as "pending".
        $this->orders[$orderId] = 'pending';

        $this->bus->publish('order.placed', [
            'order_id' => $orderId,
            'sku' => $sku,
            'quantity' => $quantity,
            'amount' => $amount,
        ]);
    }
}
