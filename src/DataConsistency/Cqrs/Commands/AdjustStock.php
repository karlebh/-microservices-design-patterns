<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Cqrs\Commands;

final readonly class AdjustStock
{
    /** @param int $delta positive when stock arrives, negative when it leaves */
    public function __construct(
        public string $sku,
        public int $delta,
    ) {}
}
