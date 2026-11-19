<?php

declare(strict_types=1);

namespace Patterns\Decomposition\MicroFrontends\Teams;

use Patterns\Decomposition\MicroFrontends\MicroFrontend;

/** Owned by the catalog team. */
final class ProductDetailsFragment implements MicroFrontend
{
    public function __construct(private readonly string $headline = 'Mechanical Keyboard') {}

    public function team(): string
    {
        return 'catalog';
    }

    public function render(array $context): string
    {
        return "<h1>{$this->headline}</h1><p>SKU {$context['sku']}</p>";
    }

    public function assets(): array
    {
        return ['https://cdn.example/design-system.js', 'https://cdn.example/catalog/product.js'];
    }
}
