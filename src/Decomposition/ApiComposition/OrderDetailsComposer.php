<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ApiComposition;

use Patterns\Decomposition\ApiComposition\Services\CustomerService;
use Patterns\Decomposition\ApiComposition\Services\OrderService;
use Patterns\Decomposition\ApiComposition\Services\ProductService;
use Throwable;

/**
 * The API composer: implements a query that spans several services by
 * calling each data owner and joining the answers in memory.
 *
 * It is the application-level replacement for:
 *
 *   SELECT ... FROM orders
 *   JOIN customers ON ...
 *   JOIN products  ON ...
 *
 * which is no longer possible once each table lives in a different service.
 */
final class OrderDetailsComposer
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CustomerService $customers,
        private readonly ProductService $products,
    ) {}

    public function orderDetails(int $orderId): ?array
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            return null;
        }

        // In a real composer these two calls run in parallel: they don't depend on each other.
        $customer = $this->customerOrNull($order['customer_id']);
        $products = $this->products->getMany(array_column($order['lines'], 'sku')); // one call, not one per line

        $lines = [];
        $totalCents = 0;

        foreach ($order['lines'] as $line) {
            $product = $products[$line['sku']] ?? null;
            $subtotal = ($product['price_cents'] ?? 0) * $line['quantity'];
            $totalCents += $subtotal;

            // The in-memory "join": order line + product.
            $lines[] = [
                'sku' => $line['sku'],
                'title' => $product['title'] ?? '(unknown product)',
                'quantity' => $line['quantity'],
                'subtotal_cents' => $subtotal,
            ];
        }

        return [
            'order_id' => $order['id'],
            'customer' => $customer === null ? null : ['name' => $customer['name'], 'email' => $customer['email']],
            'lines' => $lines,
            'total_cents' => $totalCents,
            // Tell the client honestly when part of the picture is missing.
            'complete' => $customer !== null,
        ];
    }

    /**
     * The same query written naively: one product lookup per order line.
     * Kept here to make the cost of the N+1 pattern measurable in a test.
     */
    public function orderDetailsWithOneCallPerLine(int $orderId): ?array
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            return null;
        }

        $lines = [];

        foreach ($order['lines'] as $line) {
            $product = $this->products->find($line['sku']); // a network round trip inside a loop

            $lines[] = ['sku' => $line['sku'], 'title' => $product['title'] ?? '(unknown product)', 'quantity' => $line['quantity']];
        }

        return ['order_id' => $order['id'], 'lines' => $lines];
    }

    private function customerOrNull(int $customerId): ?array
    {
        try {
            return $this->customers->find($customerId);
        } catch (Throwable) {
            // One unavailable service should cost one section of the page, not the whole page.
            return null;
        }
    }
}
