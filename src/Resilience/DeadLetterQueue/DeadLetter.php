<?php

declare(strict_types=1);

namespace Patterns\Resilience\DeadLetterQueue;

/** A message that was given up on, kept together with the evidence. */
final readonly class DeadLetter
{
    public function __construct(
        public string $id,
        public mixed $payload,
        /** How many times processing was attempted before giving up. */
        public int $attempts,
        /** The error from the final attempt. */
        public string $lastError,
        /** `retries exhausted` or `permanent failure`. */
        public string $reason,
    ) {}
}
