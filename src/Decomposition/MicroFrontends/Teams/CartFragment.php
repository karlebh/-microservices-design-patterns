<?php

declare(strict_types=1);

namespace Patterns\Decomposition\MicroFrontends\Teams;

use Patterns\Decomposition\MicroFrontends\MicroFrontend;

/** Owned by the checkout team. Reads shared context from the shell; knows no other fragment. */
final class CartFragment implements MicroFrontend
{
    public function team(): string
    {
        return 'checkout';
    }

    public function render(array $context): string
    {
        $user = $context['user'] ?? null;

        return $user === null
            ? '<a href="/login">Sign in to see your cart</a>'
            : "<p>{$user}'s cart</p><button>Add to cart</button>";
    }

    public function assets(): array
    {
        return ['https://cdn.example/design-system.js', 'https://cdn.example/checkout/cart.js'];
    }
}
