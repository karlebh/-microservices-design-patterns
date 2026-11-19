<?php

declare(strict_types=1);

namespace Patterns\Resilience\CircuitBreaker;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Circuit Breaker Implementation
 *
 * Prevents repeated calls to a failing external dependency (e.g. payment API).
 *
 * Behaviour:
 * - Tracks consecutive failures using a cache store
 * - Opens the circuit after a defined threshold of failures
 * - While the circuit is open, requests fail fast without calling the external service
 * - After a cooldown period, lets ONE trial request through (half-open)
 *
 * States:
 * - CLOSED: Normal operation, requests are allowed
 * - OPEN: External service is considered unhealthy, requests are blocked
 * - HALF-OPEN: Used to test recovery before fully closing the circuit
 *
 * Purpose:
 * - Prevent cascading failures
 * - Reduce latency caused by timeouts
 * - Protect application and external service stability
 * - Improve system resilience under partial outages
 *
 * The state is kept in the cache (not in this object) because PHP workers
 * share no memory: all of them must see the same circuit.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly string $service,
        private readonly Cache $cache,
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 30,
        /** Failures older than this no longer count towards the threshold. */
        private readonly int $failureWindowSeconds = 120,
    ) {}

    /**
     * Run $operation under the protection of the breaker.
     *
     * @param  Closure(Throwable): mixed|null  $fallback  optional degraded answer, used both when the
     *                                                    circuit rejects the call and when the call fails
     */
    public function call(Closure $operation, ?Closure $fallback = null): mixed
    {
        $state = $this->state();

        // OPEN: fail fast. HALF-OPEN: only the first caller gets to probe.
        if ($state === CircuitState::Open || ($state === CircuitState::HalfOpen && ! $this->claimProbe())) {
            $rejection = CircuitOpenException::for($this->service);

            if ($fallback !== null) {
                return $fallback($rejection);
            }

            throw $rejection;
        }

        try {
            $result = $operation();
        } catch (Throwable $e) {
            $this->recordFailure($state);

            if ($fallback !== null) {
                return $fallback($e);
            }

            throw $e;
        }

        $this->recordSuccess();

        return $result;
    }

    public function state(): CircuitState
    {
        $openedAt = $this->cache->get($this->key('opened_at'));

        if ($openedAt === null) {
            return CircuitState::Closed;
        }

        return now()->getTimestamp() - (int) $openedAt >= $this->openSeconds
            ? CircuitState::HalfOpen
            : CircuitState::Open;
    }

    private function recordFailure(CircuitState $stateBeforeCall): void
    {
        // The trial request failed: the dependency is still unhealthy.
        if ($stateBeforeCall === CircuitState::HalfOpen) {
            $this->trip();

            return;
        }

        // add() creates the counter with a TTL if missing; increment() is atomic.
        $this->cache->add($this->key('failures'), 0, $this->failureWindowSeconds);

        if ($this->cache->increment($this->key('failures')) >= $this->failureThreshold) {
            $this->trip();
        }
    }

    private function recordSuccess(): void
    {
        // Any success means "healthy": close the circuit and start counting from zero.
        $this->cache->forget($this->key('failures'));
        $this->cache->forget($this->key('opened_at'));
        $this->cache->forget($this->key('probe'));
    }

    /** Open the circuit (again) and restart the cooldown. */
    private function trip(): void
    {
        $this->cache->forever($this->key('opened_at'), now()->getTimestamp());
        $this->cache->forget($this->key('failures'));
        $this->cache->forget($this->key('probe'));
    }

    /**
     * add() only succeeds for the first caller, so exactly one request probes
     * the dependency. The TTL frees the slot if that request never reports back.
     */
    private function claimProbe(): bool
    {
        return $this->cache->add($this->key('probe'), true, $this->openSeconds);
    }

    private function key(string $suffix): string
    {
        return "circuit_breaker:{$this->service}:{$suffix}";
    }
}
