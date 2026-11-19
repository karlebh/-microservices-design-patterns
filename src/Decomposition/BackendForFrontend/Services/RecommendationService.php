<?php

declare(strict_types=1);

namespace Patterns\Decomposition\BackendForFrontend\Services;

/** Downstream service: "customers also bought". Comparatively slow and expensive. */
final class RecommendationService
{
    public int $calls = 0;

    /** @return list<array{sku: string, name: string}> */
    public function relatedTo(string $sku): array
    {
        $this->calls++;

        return [
            ['sku' => 'wrist-rest', 'name' => 'Wrist Rest'],
            ['sku' => 'keycaps', 'name' => 'Keycap Set'],
        ];
    }
}
