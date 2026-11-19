<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\CacheStampede;

use Closure;
use Patterns\Support\Clock;
use Random\Randomizer;

/**
 * A cache in which recomputing a value takes time, so that the stampede —
 * and each way of preventing it — can be observed.
 *
 * PHP runs one request at a time, so parallel requests are modelled with
 * the clock: a recomputation started at time t is "in flight" until
 * t + computeMs, and only then does its result appear in the cache.
 * Requests that arrive in between see what concurrent requests would see.
 */
final class StampedeCache
{
    /** @var array<string, array{value: mixed, expiresAt: int}> */
    private array $entries = [];

    /** @var array<string, array{value: mixed, readyAt: int}> recomputations that have started but not finished */
    private array $inFlight = [];

    /** How many times the expensive computation ran: the load on the database. */
    public int $recomputations = 0;

    /** @var array<string, int> what happened to each request */
    public array $outcomes = ['hit' => 0, 'miss' => 0, 'waited' => 0, 'stale' => 0, 'early-refresh' => 0];

    private readonly Randomizer $random;

    /**
     * @param  int  $computeMs  how long one recomputation takes
     * @param  float  $beta  XFetch aggressiveness: above 1 refreshes earlier, below 1 later
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly Protection $protection,
        private readonly int $ttlMs,
        private readonly int $computeMs,
        private readonly float $beta = 1.0,
        ?Randomizer $random = null,
    ) {
        $this->random = $random ?? new Randomizer;
    }

    /** @param Closure(): mixed $compute the expensive query */
    public function get(string $key, Closure $compute): mixed
    {
        $now = $this->clock->nowMs();
        $this->storeFinishedRecomputation($key, $now);

        $entry = $this->entries[$key] ?? null;
        $fresh = $entry !== null && $entry['expiresAt'] > $now;
        $someoneIsRecomputing = isset($this->inFlight[$key]);

        if ($fresh) {
            if ($this->protection === Protection::ProbabilisticEarlyExpiration
                && ! $someoneIsRecomputing
                && $this->shouldRefreshEarly($entry['expiresAt'], $now)) {
                // Volunteer to refresh before anyone is forced to. Everyone,
                // including this request, keeps getting the current value.
                $this->startRecomputation($key, $compute, $now);
                $this->outcomes['early-refresh']++;

                return $entry['value'];
            }

            $this->outcomes['hit']++;

            return $entry['value'];
        }

        // ---- The entry is missing or expired -------------------------------------------

        if ($this->protection === Protection::None) {
            // Nobody knows about anybody else: each request does the work itself.
            $this->outcomes['miss']++;

            return $this->startRecomputation($key, $compute, $now);
        }

        if ($this->protection === Protection::StaleWhileRevalidate && $entry !== null) {
            if (! $someoneIsRecomputing) {
                $this->startRecomputation($key, $compute, $now);
            }

            $this->outcomes['stale']++;

            return $entry['value']; // old, but instant
        }

        if ($someoneIsRecomputing) {
            // The lock is taken: block until the holder is done, then use its result.
            $this->outcomes['waited']++;

            return $this->inFlight[$key]['value'];
        }

        $this->outcomes['miss']++;

        return $this->startRecomputation($key, $compute, $now);
    }

    private function startRecomputation(string $key, Closure $compute, int $now): mixed
    {
        $this->recomputations++;

        $value = $compute();

        // The first recomputation to start is the one whose result lands in the cache.
        $this->inFlight[$key] ??= ['value' => $value, 'readyAt' => $now + $this->computeMs];

        return $value;
    }

    private function storeFinishedRecomputation(string $key, int $now): void
    {
        $pending = $this->inFlight[$key] ?? null;

        if ($pending !== null && $pending['readyAt'] <= $now) {
            $this->entries[$key] = ['value' => $pending['value'], 'expiresAt' => $pending['readyAt'] + $this->ttlMs];

            unset($this->inFlight[$key]);
        }
    }

    /**
     * XFetch: pretend the current time is a little later than it is, by a
     * random amount proportional to how long the value takes to compute.
     * If that pretend-time is past the expiry, refresh now.
     *
     * -ln(rand) is usually small and occasionally large, so far from expiry
     * almost nobody refreshes, and close to it almost everybody would.
     */
    private function shouldRefreshEarly(int $expiresAt, int $now): bool
    {
        $rand = max($this->random->nextFloat(), PHP_FLOAT_MIN); // ln(0) is undefined

        return $now - $this->computeMs * $this->beta * log($rand) >= $expiresAt;
    }
}
