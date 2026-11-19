<?php

declare(strict_types=1);

namespace Tests\Patterns\Decomposition;

use Patterns\Decomposition\BackendForFrontend\MobileBff;
use Patterns\Decomposition\BackendForFrontend\Services\CatalogService;
use Patterns\Decomposition\BackendForFrontend\Services\RecommendationService;
use Patterns\Decomposition\BackendForFrontend\Services\ReviewService;
use Patterns\Decomposition\BackendForFrontend\WebBff;
use PHPUnit\Framework\TestCase;

final class BackendForFrontendTest extends TestCase
{
    private CatalogService $catalog;

    private ReviewService $reviews;

    private RecommendationService $recommendations;

    protected function setUp(): void
    {
        $this->catalog = new CatalogService;
        $this->reviews = new ReviewService;
        $this->recommendations = new RecommendationService;
    }

    private function mobile(): MobileBff
    {
        return new MobileBff($this->catalog, $this->reviews);
    }

    private function web(): WebBff
    {
        return new WebBff($this->catalog, $this->reviews, $this->recommendations);
    }

    public function test_the_mobile_bff_returns_only_what_a_small_screen_shows(): void
    {
        $this->assertSame([
            'name' => 'Mechanical Keyboard',
            'price' => '$129.00',
            'thumbnail' => 'https://cdn.example/kb-1/thumb.jpg',
            'rating' => ['average' => 4.0, 'count' => 3],
        ], $this->mobile()->productScreen('kb-1'));
    }

    public function test_the_web_bff_returns_everything_a_full_page_needs(): void
    {
        $page = $this->web()->productPage('kb-1');

        $this->assertSame(['thumb', 'large', 'zoom'], array_keys($page['images']));
        $this->assertCount(3, $page['reviews']['items']);
        $this->assertCount(2, $page['related']);
        $this->assertArrayHasKey('specs', $page);
        $this->assertStringContainsString('hot-swappable', $page['description']);
    }

    public function test_one_client_request_replaces_several_calls_to_downstream_services(): void
    {
        $this->web()->productPage('kb-1'); // the browser made ONE request

        $this->assertSame(
            [1, 1, 1],
            [$this->catalog->calls, $this->reviews->calls, $this->recommendations->calls],
            'The fan-out to three services happened server-side.'
        );
    }

    public function test_the_mobile_bff_skips_work_its_screen_does_not_need(): void
    {
        $this->mobile()->productScreen('kb-1');

        $this->assertSame(0, $this->recommendations->calls, 'No recommendations on mobile, so no call at all.');
    }

    public function test_the_mobile_payload_is_a_fraction_of_the_web_payload(): void
    {
        $mobileBytes = strlen(json_encode($this->mobile()->productScreen('kb-1')));
        $webBytes = strlen(json_encode($this->web()->productPage('kb-1')));

        $this->assertLessThan($webBytes / 3, $mobileBytes);
    }

    public function test_each_bff_chooses_its_own_way_to_degrade(): void
    {
        $this->reviews->down = true;

        $screen = $this->mobile()->productScreen('kb-1');
        $page = $this->web()->productPage('kb-1');

        $this->assertNull($screen['rating'], 'Mobile: drop the stars silently.');
        $this->assertSame('Mechanical Keyboard', $screen['name'], 'The rest of the screen is unaffected.');

        $this->assertFalse($page['reviews']['available']);
        $this->assertSame('Reviews are temporarily unavailable.', $page['reviews']['notice'], 'Web: keep the section and explain.');
    }

    public function test_the_same_data_is_formatted_per_client(): void
    {
        $this->assertSame('$129.00', $this->mobile()->productScreen('kb-1')['price'], 'Ready to render.');
        $this->assertSame(
            ['amount' => 129, 'currency' => 'USD'],
            $this->web()->productPage('kb-1')['price'],
            'The web app formats prices itself, per locale.'
        );
    }
}
