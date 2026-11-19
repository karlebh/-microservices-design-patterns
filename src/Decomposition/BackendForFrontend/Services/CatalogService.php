<?php

declare(strict_types=1);

namespace Patterns\Decomposition\BackendForFrontend\Services;

/** Downstream service: owns product data. General-purpose, not shaped for any UI. */
final class CatalogService
{
    public int $calls = 0;

    public function product(string $sku): array
    {
        $this->calls++;

        return [
            'sku' => $sku,
            'name' => 'Mechanical Keyboard',
            'description' => 'A tenkeyless mechanical keyboard with hot-swappable switches, PBT keycaps and a detachable USB-C cable.',
            'price_cents' => 12_900,
            'currency' => 'USD',
            'images' => [
                ['size' => 'thumb', 'url' => "https://cdn.example/{$sku}/thumb.jpg"],
                ['size' => 'large', 'url' => "https://cdn.example/{$sku}/large.jpg"],
                ['size' => 'zoom', 'url' => "https://cdn.example/{$sku}/zoom.jpg"],
            ],
            'specs' => ['layout' => 'TKL', 'switches' => 'Brown', 'weight_g' => 780],
        ];
    }
}
