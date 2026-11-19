<?php

declare(strict_types=1);

namespace Patterns\Resilience\Bulkhead;

use RuntimeException;

/** The compartment is at capacity; the call was rejected without being attempted. */
final class BulkheadFullException extends RuntimeException
{
    public static function for(string $bulkhead): self
    {
        return new self("Bulkhead [{$bulkhead}] is full.");
    }
}
