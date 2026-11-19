<?php

declare(strict_types=1);

namespace Patterns\Resilience\CircuitBreaker;

use RuntimeException;

/** The call was rejected without being attempted because the circuit is open. */
final class CircuitOpenException extends RuntimeException
{
    public static function for(string $service): self
    {
        return new self("[{$service}] is temporarily unavailable (circuit open).");
    }
}
