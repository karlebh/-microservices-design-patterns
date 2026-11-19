<?php

declare(strict_types=1);

namespace Patterns\Decomposition\BackendForFrontend\Services;

use RuntimeException;

/** Downstream service: owns customer reviews. */
final class ReviewService
{
    public int $calls = 0;

    /** Simulates an outage. */
    public bool $down = false;

    /** @return list<array{author: string, stars: int, text: string}> */
    public function reviewsFor(string $sku): array
    {
        $this->calls++;

        if ($this->down) {
            throw new RuntimeException('Review service is unavailable.');
        }

        return [
            ['author' => 'Ada', 'stars' => 5, 'text' => 'Best keyboard I have owned.'],
            ['author' => 'Bob', 'stars' => 4, 'text' => 'Great feel, a bit loud.'],
            ['author' => 'Cy', 'stars' => 3, 'text' => 'Fine, but the cable is short.'],
        ];
    }
}
