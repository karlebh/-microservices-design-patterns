<?php

declare(strict_types=1);

namespace Patterns\Decomposition\BackendForFrontend;

use Patterns\Decomposition\BackendForFrontend\Services\CatalogService;
use Patterns\Decomposition\BackendForFrontend\Services\RecommendationService;
use Patterns\Decomposition\BackendForFrontend\Services\ReviewService;
use Throwable;

/**
 * The backend for the WEB app, owned by the web team.
 *
 * Design goal: fill a large product page in one request — full description,
 * the zoomable image, the spec table, every review, and recommendations.
 */
final class WebBff
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly ReviewService $reviews,
        private readonly RecommendationService $recommendations,
    ) {}

    public function productPage(string $sku): array
    {
        $product = $this->catalog->product($sku);

        return [
            'name' => $product['name'],
            'description' => $product['description'],
            'price' => ['amount' => $product['price_cents'] / 100, 'currency' => $product['currency']],
            'images' => array_column($product['images'], 'url', 'size'),
            'specs' => $product['specs'],
            'reviews' => $this->reviews($sku),
            'related' => $this->recommendations->relatedTo($sku),
        ];
    }

    private function reviews(string $sku): array
    {
        try {
            return ['available' => true, 'items' => $this->reviews->reviewsFor($sku)];
        } catch (Throwable) {
            // Web's choice: keep the section and tell the user why it is empty.
            return ['available' => false, 'items' => [], 'notice' => 'Reviews are temporarily unavailable.'];
        }
    }
}
