<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Cqrs\Read;

/**
 * The query side.
 *
 * Rows are stored exactly as the product page wants them: price already
 * formatted, stock already turned into a label. Queries only look things up.
 * No business rule lives here; this model can't refuse anything.
 */
final class ProductReadModel
{
    /** @var array<string, array{sku: string, name: string, price: string, stock: int, availability: string}> */
    private array $rows = [];

    /** Called by the projector only. */
    public function upsert(string $sku, array $changes): void
    {
        $this->rows[$sku] = array_merge(
            $this->rows[$sku] ?? ['sku' => $sku, 'name' => '', 'price' => '', 'stock' => 0, 'availability' => 'Sold out'],
            $changes,
        );
    }

    public function clear(): void
    {
        $this->rows = [];
    }

    // ---- Queries -----------------------------------------------------------------------

    /** @return array{sku: string, name: string, price: string, stock: int, availability: string}|null */
    public function find(string $sku): ?array
    {
        return $this->rows[$sku] ?? null;
    }

    /** @return list<string> SKUs a customer can buy right now */
    public function available(): array
    {
        return array_keys(array_filter($this->rows, fn (array $row) => $row['stock'] > 0));
    }

    /** @return list<string> SKUs the purchasing team should reorder */
    public function lowStock(): array
    {
        return array_keys(array_filter($this->rows, fn (array $row) => $row['availability'] === 'Low stock'));
    }
}
