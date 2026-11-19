<?php

declare(strict_types=1);

namespace Patterns\Decomposition\DomainDrivenDesign\Sales;

/**
 * A domain event: something the business cares about that has happened.
 *
 * This is what the Sales context PUBLISHES to the rest of the company.
 * It contains plain data only (no Order, no Money objects), so other
 * contexts can consume it without depending on Sales' internal model.
 */
final readonly class OrderPlaced
{
    /** @param list<array{sku: string, quantity: int}> $lines */
    public function __construct(
        public string $orderId,
        public string $customerId,
        public array $lines,
        public int $totalCents,
        public string $currency,
        public string $deliveryAddress,
    ) {}
}
