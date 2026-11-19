<?php

declare(strict_types=1);

namespace Patterns\Decomposition\DomainDrivenDesign\Sales;

use DomainException;

/**
 * The aggregate root of the Sales context.
 *
 * Everything that must stay consistent about an order lives behind this
 * class, and the rules are enforced HERE rather than in controllers or
 * services. Method names are the words the sales team uses.
 *
 * Other aggregates are referenced by id only (customerId), never as objects.
 */
final class Order
{
    /** @var array<string, OrderLine> sku => line */
    private array $lines = [];

    private bool $placed = false;

    /** @var list<object> domain events raised but not yet published */
    private array $events = [];

    public function __construct(
        public readonly string $id,
        public readonly string $customerId,
        private readonly string $currency = 'USD',
    ) {}

    public function addItem(string $sku, Money $unitPrice, int $quantity): void
    {
        $this->assertNotPlaced();

        if ($quantity <= 0) {
            throw new DomainException('Quantity must be at least 1.');
        }

        // Invariant: one line per product. Adding it again raises the quantity.
        if (isset($this->lines[$sku])) {
            $this->lines[$sku]->increaseQuantity($quantity);

            return;
        }

        $this->lines[$sku] = new OrderLine($sku, $unitPrice, $quantity);
    }

    public function removeItem(string $sku): void
    {
        $this->assertNotPlaced();

        unset($this->lines[$sku]);
    }

    /** The customer commits to the order. After this it is a fact and cannot change. */
    public function place(string $deliveryAddress): void
    {
        $this->assertNotPlaced();

        if ($this->lines === []) {
            throw new DomainException('An empty order cannot be placed.');
        }

        $this->placed = true;

        $this->events[] = new OrderPlaced(
            orderId: $this->id,
            customerId: $this->customerId,
            lines: array_map(
                fn (OrderLine $line) => ['sku' => $line->sku, 'quantity' => $line->quantity()],
                array_values($this->lines),
            ),
            totalCents: $this->total()->cents,
            currency: $this->currency,
            deliveryAddress: $deliveryAddress,
        );
    }

    /** Always derived from the lines, so it can never disagree with them. */
    public function total(): Money
    {
        return array_reduce(
            $this->lines,
            fn (Money $sum, OrderLine $line) => $sum->add($line->subtotal()),
            Money::zero($this->currency),
        );
    }

    public function isPlaced(): bool
    {
        return $this->placed;
    }

    /** Read-only view for callers. They get data, not the entities themselves. */
    public function quantityOf(string $sku): int
    {
        return isset($this->lines[$sku]) ? $this->lines[$sku]->quantity() : 0;
    }

    /** @return list<object> */
    public function pullEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function assertNotPlaced(): void
    {
        if ($this->placed) {
            throw new DomainException('A placed order can no longer be changed.');
        }
    }
}
