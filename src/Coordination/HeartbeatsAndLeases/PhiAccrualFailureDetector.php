<?php

declare(strict_types=1);

namespace Patterns\Coordination\HeartbeatsAndLeases;

use Patterns\Support\Clock;

/**
 * The phi-accrual failure detector (Hayashibara et al.).
 *
 * It learns each node's normal heartbeat rhythm and expresses doubt as a
 * number instead of a verdict:
 *
 *   phi = -log10( probability that a heartbeat is merely this late )
 *
 *   phi 1  ~ 10% chance the node is actually fine
 *   phi 2  ~ 1%
 *   phi 8  ~ 0.000001%   (a common "consider it dead" threshold)
 *
 * Because it is relative to the observed intervals, the same detector
 * works for a node that reports every 100 ms and one that reports every
 * 30 s, without separate timeouts.
 *
 * This version models heartbeat gaps with an exponential distribution,
 * which is the simplification Cassandra uses.
 */
final class PhiAccrualFailureDetector
{
    private const WINDOW = 100;

    /** @var array<string, int> */
    private array $lastHeartbeat = [];

    /** @var array<string, list<int>> node => recent gaps between heartbeats */
    private array $intervals = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly float $threshold = 8.0,
    ) {}

    public function heartbeat(string $node): void
    {
        $now = $this->clock->nowMs();

        if (isset($this->lastHeartbeat[$node])) {
            $this->intervals[$node][] = $now - $this->lastHeartbeat[$node];
            $this->intervals[$node] = array_slice($this->intervals[$node], -self::WINDOW);
        }

        $this->lastHeartbeat[$node] = $now;
    }

    /** The current level of suspicion: 0 right after a heartbeat, rising with silence. */
    public function phi(string $node): float
    {
        if (empty($this->intervals[$node])) {
            return 0.0; // not enough history to judge
        }

        $meanInterval = array_sum($this->intervals[$node]) / count($this->intervals[$node]);
        $silence = $this->clock->nowMs() - $this->lastHeartbeat[$node];

        // P(gap > silence) = e^(-silence / mean)   =>   phi = silence / (mean * ln 10)
        return $silence / ($meanInterval * log(10));
    }

    public function isAlive(string $node): bool
    {
        return $this->phi($node) < $this->threshold;
    }
}
