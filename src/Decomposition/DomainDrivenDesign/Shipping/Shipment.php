<?php

declare(strict_types=1);

namespace Patterns\Decomposition\DomainDrivenDesign\Shipping;

use DomainException;

/**
 * The aggregate root of the Shipping context.
 *
 * Note the vocabulary: recipient, parcel, dispatch, weight. There is no
 * price and no "order" here. Shipping has its own model of the same
 * real-world purchase, built for its own job.
 */
final class Shipment
{
    public const MAX_WEIGHT_GRAMS = 30_000;

    /** @var list<array{sku: string, weightGrams: int}> */
    private array $parcels = [];

    private bool $dispatched = false;

    public function __construct(
        public readonly string $id,
        public readonly string $recipientId,
        public readonly string $address,
    ) {}

    public function addParcel(string $sku, int $weightGrams): void
    {
        if ($this->dispatched) {
            throw new DomainException('A dispatched shipment can no longer be changed.');
        }

        // Invariant owned by this context: a courier takes at most 30 kg per shipment.
        if ($this->totalWeightGrams() + $weightGrams > self::MAX_WEIGHT_GRAMS) {
            throw new DomainException('A shipment may not exceed 30 kg.');
        }

        $this->parcels[] = ['sku' => $sku, 'weightGrams' => $weightGrams];
    }

    public function dispatch(): void
    {
        if ($this->parcels === []) {
            throw new DomainException('A shipment without parcels cannot be dispatched.');
        }

        $this->dispatched = true;
    }

    public function totalWeightGrams(): int
    {
        return array_sum(array_column($this->parcels, 'weightGrams'));
    }

    public function parcelCount(): int
    {
        return count($this->parcels);
    }

    public function isDispatched(): bool
    {
        return $this->dispatched;
    }
}
