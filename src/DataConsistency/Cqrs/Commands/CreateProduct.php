<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Cqrs\Commands;

/** A command is a request to change something, expressed as plain data. */
final readonly class CreateProduct
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $priceCents,
    ) {}
}
