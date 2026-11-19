<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Saga\Choreography;

/**
 * Reserves stock when an order is placed, and gives it back if the saga
 * fails later on. The second subscription IS the compensating transaction.
 */
final class InventoryService
{
    /** @var array<string, array{sku: string, quantity: int}> order id => what we reserved for it */
    private array $reservations = [];

    /** @param array<string, int> $stock sku => units available */
    public function __construct(private readonly EventBus $bus, public array $stock)
    {
        $bus->subscribe('order.placed', $this->reserve(...));
        $bus->subscribe('payment.failed', $this->release(...));
    }

    private function reserve(array $order): void
    {
        if (($this->stock[$order['sku']] ?? 0) < $order['quantity']) {
            $this->bus->publish('inventory.rejected', ['order_id' => $order['order_id']]);

            return;
        }

        // Local transaction #2.
        $this->stock[$order['sku']] -= $order['quantity'];
        $this->reservations[$order['order_id']] = ['sku' => $order['sku'], 'quantity' => $order['quantity']];

        $this->bus->publish('inventory.reserved', $order);
    }

    private function release(array $event): void
    {
        $reservation = $this->reservations[$event['order_id']] ?? null;

        // Idempotent: a redelivered failure event must not add the stock back twice.
        if ($reservation === null) {
            return;
        }

        $this->stock[$reservation['sku']] += $reservation['quantity'];
        unset($this->reservations[$event['order_id']]);

        $this->bus->publish('inventory.released', ['order_id' => $event['order_id']]);
    }
}
