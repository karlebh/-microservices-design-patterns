<?php

declare(strict_types=1);

namespace Patterns\Decomposition\MicroFrontends\Teams;

use Patterns\Decomposition\MicroFrontends\MicroFrontend;
use RuntimeException;

/** Owned by the reviews team. Can be told to fail, to show failure isolation. */
final class ReviewsFragment implements MicroFrontend
{
    public function __construct(private readonly bool $broken = false) {}

    public function team(): string
    {
        return 'reviews';
    }

    public function render(array $context): string
    {
        if ($this->broken) {
            throw new RuntimeException('Review service timed out.');
        }

        return '<h2>Reviews</h2><p>4.6 out of 5 stars</p>';
    }

    public function assets(): array
    {
        return ['https://cdn.example/design-system.js', 'https://cdn.example/reviews/stars.js'];
    }
}
