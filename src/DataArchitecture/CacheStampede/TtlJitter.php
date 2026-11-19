<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\CacheStampede;

use Random\Randomizer;

/**
 * Randomises TTLs.
 *
 * A deploy, a cache warm-up or a restart caches thousands of entries in
 * the same second. With identical TTLs they also all EXPIRE in the same
 * second, and the database gets the whole reload at once. A little
 * randomness spreads those expiries over a window.
 */
final class TtlJitter
{
    public function __construct(private readonly Randomizer $random = new Randomizer) {}

    /**
     * @param  float  $spread  0.1 = anywhere within 10% above or below the base TTL
     */
    public function apply(int $baseTtlMs, float $spread = 0.1): int
    {
        $delta = (int) round($baseTtlMs * $spread);

        return $baseTtlMs + $this->random->getInt(-$delta, $delta);
    }
}
