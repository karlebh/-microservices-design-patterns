<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Patterns\DataConsistency\TransactionalOutbox\Models\Order;
use Patterns\DataConsistency\TransactionalOutbox\Models\OutboxMessage;

/**
 * The write side of the outbox pattern.
 *
 * Note what is NOT here: no broker client, no HTTP call, no queue dispatch.
 * The only thing this action talks to is its own database, so the order and
 * the fact "an order was placed" either both commit or both roll back.
 */
final class PlaceOrder
{
    public function handle(string $customer, int $totalCents): Order
    {
        return DB::transaction(function () use ($customer, $totalCents) {
            $order = Order::create([
                'customer' => $customer,
                'total_cents' => $totalCents,
                'status' => 'placed',
            ]);

            OutboxMessage::create([
                'message_id' => (string) Str::uuid(),
                'aggregate_type' => 'order',
                'aggregate_id' => (string) $order->id,
                'type' => 'order.placed',
                'payload' => [
                    'order_id' => $order->id,
                    'customer' => $customer,
                    'total_cents' => $totalCents,
                ],
            ]);

            return $order;
        });
    }
}
