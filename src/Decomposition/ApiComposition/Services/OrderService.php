<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ApiComposition\Services;

/**
 * Owns the orders database, and only that.
 *
 * An order refers to a customer and to products BY ID. It holds no customer
 * name and no product title: that data belongs to other services.
 */
final class OrderService
{
    public int $calls = 0;

    /** @var array<int, array{id: int, customer_id: int, lines: list<array{sku: string, quantity: int}>}> */
    private array $orders = [
        42 => [
            'id' => 42,
            'customer_id' => 7,
            'lines' => [
                ['sku' => 'book', 'quantity' => 2],
                ['sku' => 'pen', 'quantity' => 5],
                ['sku' => 'lamp', 'quantity' => 1],
            ],
        ],
    ];

    public function find(int $orderId): ?array
    {
        $this->calls++;

        return $this->orders[$orderId] ?? null;
    }
}
