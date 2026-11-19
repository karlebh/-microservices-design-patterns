<?php

declare(strict_types=1);

namespace Patterns\Resilience\GracefulDegradation;

/**
 * A value together with an honest account of where it came from.
 *
 * Returning only the value would make a fallback indistinguishable from
 * the real thing — convenient for the caller, and invisible in monitoring.
 */
final readonly class Outcome
{
    /**
     * @param  string  $source  which link of the chain produced the value
     * @param  bool  $degraded  true when it did not come from the primary source
     * @param  array<string, string>  $failures  source => error message, for every source tried before this one
     */
    public function __construct(
        public mixed $value,
        public string $source,
        public bool $degraded,
        public array $failures = [],
    ) {}
}
