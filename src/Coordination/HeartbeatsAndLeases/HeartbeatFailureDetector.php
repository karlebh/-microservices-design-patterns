<?php

declare(strict_types=1);

namespace Patterns\Coordination\HeartbeatsAndLeases;

use Patterns\Support\Clock;

/**
 * The simplest failure detector: a node that has been silent for longer
 * than a fixed timeout is suspected of having failed.
 *
 * "Suspected" is the honest word. The node may be dead, or merely slow or
 * unreachable; from the outside these look identical.
 */
final class HeartbeatFailureDetector
{
    /** @var array<string, int> node => time of its last heartbeat */
    private array $lastHeartbeat = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $timeoutMs,
    ) {}

    public function heartbeat(string $node): void
    {
        $this->lastHeartbeat[$node] = $this->clock->nowMs();
    }

    public function isAlive(string $node): bool
    {
        return isset($this->lastHeartbeat[$node])
            && $this->clock->nowMs() - $this->lastHeartbeat[$node] <= $this->timeoutMs;
    }

    /** @return list<string> */
    public function suspected(): array
    {
        return array_values(array_filter(array_keys($this->lastHeartbeat), fn (string $node) => ! $this->isAlive($node)));
    }
}
