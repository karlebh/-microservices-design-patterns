<?php

declare(strict_types=1);

namespace Patterns\Decomposition\DomainDrivenDesign\Sales;

/**
 * An entity that lives INSIDE the Order aggregate.
 *
 * It has identity (the SKU within its order) and state that changes, but
 * nothing outside the aggregate may hold on to it or modify it. All changes
 * go through Order, which is why the mutating method is marked internal.
 */
final class OrderLine
{
    public function __construct(
        public readonly string $sku,
        public readonly Money $unitPrice,
        private int $quantity,
    ) {}

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }

    /** @internal only the aggregate root may call this */
    public function increaseQuantity(int $by): void
    {
        $this->quantity += $by;
    }
}
