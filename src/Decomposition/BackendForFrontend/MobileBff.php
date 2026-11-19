<?php

declare(strict_types=1);

namespace Patterns\Decomposition\BackendForFrontend;

use Patterns\Decomposition\BackendForFrontend\Services\CatalogService;
use Patterns\Decomposition\BackendForFrontend\Services\ReviewService;
use Throwable;

/**
 * The backend for the MOBILE app, owned by the mobile team.
 *
 * Design goals: one request, few bytes. So it sends a thumbnail instead of
 * all images, a rating summary instead of review texts, a ready-to-display
 * price, and it does not call the recommendation service at all.
 */
final class MobileBff
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly ReviewService $reviews,
    ) {}

    public function productScreen(string $sku): array
    {
        $product = $this->catalog->product($sku);

        return [
            'name' => $product['name'],
            'price' => $this->formatPrice($product['price_cents']),
            'thumbnail' => $product['images'][0]['url'],
            'rating' => $this->ratingSummary($sku),
        ];
    }

    /** @return array{average: float, count: int}|null */
    private function ratingSummary(string $sku): ?array
    {
        try {
            $reviews = $this->reviews->reviewsFor($sku);
        } catch (Throwable) {
            // Mobile's choice: the stars are a nice-to-have. Leave them out
            // rather than fail the screen.
            return null;
        }

        return [
            'average' => round(array_sum(array_column($reviews, 'stars')) / count($reviews), 1),
            'count' => count($reviews),
        ];
    }

    private function formatPrice(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
