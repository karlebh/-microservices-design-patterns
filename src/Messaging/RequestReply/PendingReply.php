<?php

declare(strict_types=1);

namespace Patterns\Messaging\RequestReply;

use LogicException;
use RuntimeException;

/**
 * What the caller holds while a request is in flight (a minimal future).
 * It ends in exactly one of two ways: a reply arrives, or the deadline passes.
 */
final class PendingReply
{
    private string $state = 'pending';

    private mixed $value = null;

    public function __construct(
        public readonly string $correlationId,
        public readonly int $deadlineMs,
    ) {}

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isFulfilled(): bool
    {
        return $this->state === 'fulfilled';
    }

    public function hasTimedOut(): bool
    {
        return $this->state === 'timed_out';
    }

    public function value(): mixed
    {
        return match ($this->state) {
            'fulfilled' => $this->value,
            'timed_out' => throw new RuntimeException("No reply to request [{$this->correlationId}] before the deadline."),
            default => throw new LogicException("Request [{$this->correlationId}] is still waiting for its reply."),
        };
    }

    /** @internal called by the Requester */
    public function fulfil(mixed $value): void
    {
        $this->state = 'fulfilled';
        $this->value = $value;
    }

    /** @internal called by the Requester */
    public function timeOut(): void
    {
        $this->state = 'timed_out';
    }
}
