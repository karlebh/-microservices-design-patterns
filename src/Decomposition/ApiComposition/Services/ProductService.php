<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ApiComposition\Services;

/** Owns the product catalogue. */
final class ProductService
{
    public int $calls = 0;

    /** @var array<string, array{sku: string, title: string, price_cents: int}> */
    private array $products = [
        'book' => ['sku' => 'book', 'title' => 'Designing Data-Intensive Applications', 'price_cents' => 4_500],
        'pen' => ['sku' => 'pen', 'title' => 'Fountain Pen', 'price_cents' => 250],
        'lamp' => ['sku' => 'lamp', 'title' => 'Desk Lamp', 'price_cents' => 3_000],
    ];

    public function find(string $sku): ?array
    {
        $this->calls++;

        return $this->products[$sku] ?? null;
    }

    /**
     * A batch endpoint: many products in ONE round trip. Every service that
     * is going to be composed needs one of these.
     *
     * @param  list<string>  $skus
     * @return array<string, array{sku: string, title: string, price_cents: int}> keyed by sku
     */
    public function getMany(array $skus): array
    {
        $this->calls++;

        return array_intersect_key($this->products, array_flip($skus));
    }
}
