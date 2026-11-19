<?php

declare(strict_types=1);

namespace Patterns\Decomposition\DomainDrivenDesign\Sales;

use OutOfBoundsException;

/**
 * A repository gives the illusion of an in-memory collection of aggregates.
 *
 * Its unit is the WHOLE aggregate: you load an Order and save an Order.
 * There is no "save one order line" — that would let callers bypass the
 * root and break its invariants.
 *
 * Saving is also where domain events leave the aggregate to be published.
 */
final class OrderRepository
{
    /** @var array<string, Order> */
    private array $orders = [];

    /** @var list<object> events collected on save, ready for a publisher (or an outbox) */
    public array $publishedEvents = [];

    public function save(Order $order): void
    {
        $this->orders[$order->id] = $order;

        array_push($this->publishedEvents, ...$order->pullEvents());
    }

    public function get(string $id): Order
    {
        return $this->orders[$id] ?? throw new OutOfBoundsException("Order [{$id}] not found.");
    }
}
