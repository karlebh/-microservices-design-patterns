<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DistributedLocks\Chubby;

/**
 * Proof of "I held lock X at generation N".
 *
 * The holder passes this along with its requests. A server can ask the lock
 * service whether it is still current and refuse work from a stale holder.
 */
final readonly class Sequencer
{
    public function __construct(
        public string $lock,
        public int $generation,
    ) {}
}
