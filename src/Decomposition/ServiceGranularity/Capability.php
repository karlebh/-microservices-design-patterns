<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ServiceGranularity;

/**
 * A piece of functionality that could be a service of its own or part of a
 * larger one, described by the characteristics that drive that decision.
 */
final readonly class Capability
{
    /**
     * @param  int  $changesPerMonth  how often its code changes (volatility)
     * @param  int  $peakRequestsPerSecond  the load it has to handle (scalability)
     * @param  bool  $handlesSensitiveData  payment details, health data... (security)
     * @param  bool  $mustStayUp  must keep working even if its neighbours are down (fault tolerance)
     * @param  list<string>  $writesTables  data it modifies
     * @param  string  $businessArea  the business capability it belongs to
     */
    public function __construct(
        public string $name,
        public int $changesPerMonth = 1,
        public int $peakRequestsPerSecond = 10,
        public bool $handlesSensitiveData = false,
        public bool $mustStayUp = false,
        public array $writesTables = [],
        public string $businessArea = '',
    ) {}
}
