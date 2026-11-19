<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Cqrs\Commands;

final readonly class ChangePrice
{
    public function __construct(
        public string $sku,
        public int $priceCents,
    ) {}
}
