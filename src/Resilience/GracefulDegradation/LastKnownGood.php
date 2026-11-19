<?php

declare(strict_types=1);

namespace Patterns\Resilience\GracefulDegradation;

use Closure;
use Patterns\Support\Clock;
use RuntimeException;
use Throwable;

/**
 * "Serve stale on error".
 *
 * Every successful answer is remembered. When the live call fails, the
 * remembered answer is served instead, along with its age so the caller
 * can decide whether it is still good enough to show.
 *
 * It is not a cache: the live source is ALWAYS tried first. The stored
 * copy exists only for the moment the source is unavailable.
 */
final class LastKnownGood
{
    /** @var array<string, array{value: mixed, storedAt: int}> */
    private array $remembered = [];

    /** @param int|null $maxAgeMs refuse to serve copies older than this (null = any age) */
    public function __construct(
        private readonly Clock $clock,
        private readonly ?int $maxAgeMs = null,
    ) {}

    /**
     * @param  Closure(): mixed  $live
     * @return Outcome with source `live` or `last-known-good`
     *
     * @throws Throwable when the live call fails and there is no usable copy
     */
    public function fetch(string $key, Closure $live): Outcome
    {
        try {
            $value = $live();
        } catch (Throwable $e) {
            return $this->stale($key, $e);
        }

        $this->remembered[$key] = ['value' => $value, 'storedAt' => $this->clock->nowMs()];

        return new Outcome($value, 'live', degraded: false);
    }

    public function ageMs(string $key): ?int
    {
        return isset($this->remembered[$key]) ? $this->clock->nowMs() - $this->remembered[$key]['storedAt'] : null;
    }

    private function stale(string $key, Throwable $cause): Outcome
    {
        $age = $this->ageMs($key);

        if ($age === null) {
            throw $cause; // we never had a good answer to fall back on
        }

        if ($this->maxAgeMs !== null && $age > $this->maxAgeMs) {
            throw new RuntimeException("The last known value for [{$key}] is too old to serve.", previous: $cause);
        }

        return new Outcome(
            $this->remembered[$key]['value'],
            'last-known-good',
            degraded: true,
            failures: ['live' => $cause->getMessage()],
        );
    }
}
