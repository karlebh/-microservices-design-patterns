<?php

declare(strict_types=1);

namespace Tests\Patterns\Decomposition;

use Patterns\Decomposition\MicroFrontends\AppShell;
use Patterns\Decomposition\MicroFrontends\FragmentRegistry;
use Patterns\Decomposition\MicroFrontends\Teams\CartFragment;
use Patterns\Decomposition\MicroFrontends\Teams\ProductDetailsFragment;
use Patterns\Decomposition\MicroFrontends\Teams\ReviewsFragment;
use PHPUnit\Framework\TestCase;

final class MicroFrontendsTest extends TestCase
{
    private const CONTEXT = ['sku' => 'kb-1', 'user' => 'Ada'];

    /** @return array{AppShell, FragmentRegistry} */
    private function productPage(): array
    {
        $registry = new FragmentRegistry;
        $registry->deploy('details', new ProductDetailsFragment);
        $registry->deploy('reviews', new ReviewsFragment);
        $registry->deploy('cart', new CartFragment);

        return [new AppShell($registry, layout: ['details', 'reviews', 'cart']), $registry];
    }

    public function test_the_shell_composes_one_page_from_fragments_owned_by_different_teams(): void
    {
        [$shell] = $this->productPage();

        $html = $shell->renderPage(self::CONTEXT)['html'];

        $this->assertStringContainsString('<section data-slot="details" data-team="catalog"><h1>Mechanical Keyboard</h1>', $html);
        $this->assertStringContainsString('<section data-slot="reviews" data-team="reviews">', $html);
        $this->assertStringContainsString('<section data-slot="cart" data-team="checkout">', $html);

        $this->assertLessThan(strpos($html, 'data-slot="cart"'), strpos($html, 'data-slot="details"'), 'The shell, not the fragments, decides the layout.');
    }

    public function test_a_team_ships_a_new_version_without_touching_the_shell_or_other_teams(): void
    {
        [$shell, $registry] = $this->productPage();
        $before = $shell->renderPage(self::CONTEXT)['html'];

        // The catalog team deploys. Nobody else does anything.
        $registry->deploy('details', new ProductDetailsFragment(headline: 'Mechanical Keyboard — now with RGB'));

        $after = $shell->renderPage(self::CONTEXT)['html'];

        $this->assertStringContainsString('now with RGB', $after);
        $this->assertStringContainsString('4.6 out of 5 stars', $after, 'The other fragments are exactly as before.');
        $this->assertStringNotContainsString('now with RGB', $before);
    }

    public function test_a_broken_fragment_costs_one_section_not_the_page(): void
    {
        [$shell, $registry] = $this->productPage();
        $registry->deploy('reviews', new ReviewsFragment(broken: true));

        $page = $shell->renderPage(self::CONTEXT);

        $this->assertSame(['reviews'], $page['failedSlots']);
        $this->assertStringContainsString('temporarily unavailable', $page['html']);
        $this->assertStringContainsString('Add to cart', $page['html'], 'The customer can still buy.');
    }

    public function test_shared_dependencies_are_loaded_once(): void
    {
        [$shell] = $this->productPage();

        $assets = $shell->renderPage(self::CONTEXT)['assets'];

        $this->assertSame([
            'https://cdn.example/design-system.js',   // requested by all three fragments
            'https://cdn.example/catalog/product.js',
            'https://cdn.example/reviews/stars.js',
            'https://cdn.example/checkout/cart.js',
        ], $assets);
    }

    public function test_fragments_share_state_through_the_shell_not_through_each_other(): void
    {
        [$shell] = $this->productPage();

        $signedIn = $shell->renderPage(['sku' => 'kb-1', 'user' => 'Ada'])['html'];
        $anonymous = $shell->renderPage(['sku' => 'kb-1'])['html'];

        $this->assertStringContainsString("Ada's cart", $signedIn);
        $this->assertStringContainsString('Sign in to see your cart', $anonymous);
    }

    public function test_a_slot_with_nothing_deployed_is_simply_left_out(): void
    {
        $registry = new FragmentRegistry;
        $registry->deploy('details', new ProductDetailsFragment);
        $shell = new AppShell($registry, layout: ['details', 'recommendations', 'cart']);

        $page = $shell->renderPage(self::CONTEXT);

        $this->assertSame(1, substr_count($page['html'], '<section'), 'A team that has not shipped yet does not block the page.');
        $this->assertSame([], $page['failedSlots']);
    }
}
