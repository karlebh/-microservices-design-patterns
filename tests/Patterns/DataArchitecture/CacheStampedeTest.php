<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\CacheStampede\Protection;
use Patterns\DataArchitecture\CacheStampede\StampedeCache;
use Patterns\DataArchitecture\CacheStampede\TtlJitter;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class CacheStampedeTest extends TestCase
{
    private const TTL_MS = 10_000;

    private const COMPUTE_MS = 200;

    /**
     * A warm cache whose popular entry has JUST expired, followed by 100
     * requests arriving one millisecond apart — all within the 200 ms the
     * recomputation takes.
     */
    private function burstAfterExpiry(Protection $protection): StampedeCache
    {
        $clock = new FakeClock;
        $cache = new StampedeCache($clock, $protection, self::TTL_MS, self::COMPUTE_MS, random: new Randomizer(new Mt19937(5)));
        $version = 0;
        $compute = function () use (&$version) {
            return 'homepage v'.(++$version);
        };

        // Warm up: compute once and let it land in the cache.
        $cache->get('homepage', $compute);
        $clock->advance(self::COMPUTE_MS);
        $cache->get('homepage', $compute);

        // Jump to the moment of expiry and reset the counters.
        $clock->advance(self::TTL_MS);
        $cache->recomputations = 0;
        $cache->outcomes = array_fill_keys(array_keys($cache->outcomes), 0);

        for ($request = 0; $request < 100; $request++) {
            $cache->get('homepage', $compute);
            $clock->advance(1);
        }

        return $cache;
    }

    public function test_without_protection_every_request_recomputes_the_same_value(): void
    {
        $cache = $this->burstAfterExpiry(Protection::None);

        $this->assertSame(100, $cache->recomputations, 'One hundred identical expensive queries hit the database together.');
    }

    public function test_a_lock_lets_one_request_recompute_while_the_others_wait(): void
    {
        $cache = $this->burstAfterExpiry(Protection::Lock);

        $this->assertSame(1, $cache->recomputations);
        $this->assertSame(99, $cache->outcomes['waited'], 'The database is safe; the users are held up for the duration of one computation.');
    }

    public function test_stale_while_revalidate_keeps_everyone_served_during_the_refresh(): void
    {
        $cache = $this->burstAfterExpiry(Protection::StaleWhileRevalidate);

        $this->assertSame(1, $cache->recomputations);
        $this->assertSame(100, $cache->outcomes['stale'], 'Nobody waited: all got the previous value, instantly.');
        $this->assertSame(0, $cache->outcomes['waited']);
    }

    public function test_the_refreshed_value_is_served_once_it_is_ready(): void
    {
        $clock = new FakeClock;
        $cache = new StampedeCache($clock, Protection::StaleWhileRevalidate, self::TTL_MS, self::COMPUTE_MS);
        $version = 0;
        $compute = function () use (&$version) {
            return 'v'.(++$version);
        };

        $cache->get('k', $compute);
        $clock->advance(self::COMPUTE_MS + self::TTL_MS); // v1 was cached and has now expired

        $this->assertSame('v1', $cache->get('k', $compute), 'Stale, while v2 is being computed.');

        $clock->advance(self::COMPUTE_MS);

        $this->assertSame('v2', $cache->get('k', $compute));
    }

    public function test_probabilistic_early_expiration_refreshes_before_the_entry_ever_expires(): void
    {
        $clock = new FakeClock;
        $cache = new StampedeCache($clock, Protection::ProbabilisticEarlyExpiration, self::TTL_MS, self::COMPUTE_MS, random: new Randomizer(new Mt19937(11)));
        $compute = fn () => 'homepage';

        // Steady traffic: one request every 20 ms for a minute (six TTL periods).
        for ($elapsed = 0; $elapsed < 60_000; $elapsed += 20) {
            $cache->get('homepage', $compute);
            $clock->advance(20);
        }

        $this->assertSame(1, $cache->outcomes['miss'], 'Only the very first request, on a cold cache, ever missed.');
        $this->assertGreaterThanOrEqual(5, $cache->outcomes['early-refresh'], 'Each period, some request volunteered shortly before expiry.');
        $this->assertLessThanOrEqual(8, $cache->recomputations, 'About one recomputation per TTL period, with no locking at all.');
    }

    public function test_early_refreshes_happen_close_to_expiry_not_at_random_times(): void
    {
        $clock = new FakeClock;
        $cache = new StampedeCache($clock, Protection::ProbabilisticEarlyExpiration, self::TTL_MS, self::COMPUTE_MS, random: new Randomizer(new Mt19937(11)));

        $cache->get('k', fn () => 'v');
        $clock->advance(self::COMPUTE_MS); // cached now; expires at 200 + 10,000

        // During the first 80% of its life the entry is left alone.
        for ($elapsed = 0; $elapsed < 8_000; $elapsed += 20) {
            $cache->get('k', fn () => 'v');
            $clock->advance(20);
        }

        $this->assertSame(0, $cache->outcomes['early-refresh']);
    }

    public function test_ttl_jitter_prevents_entries_cached_together_from_expiring_together(): void
    {
        $jitter = new TtlJitter(new Randomizer(new Mt19937(2)));

        // 1,000 entries are cached during a deploy, each "for one hour".
        $expirySecond = fn (int $ttlMs) => intdiv($ttlMs, 1_000);

        $withoutJitter = array_count_values(array_map(fn () => $expirySecond(3_600_000), range(1, 1_000)));
        $withJitter = array_count_values(array_map(fn () => $expirySecond($jitter->apply(3_600_000, 0.1)), range(1, 1_000)));

        $this->assertSame(1_000, max($withoutJitter), 'All thousand expire in the same second.');
        $this->assertLessThan(10, max($withJitter), 'Spread over twelve minutes, a few per second at most.');
    }

    public function test_jitter_stays_within_the_requested_spread(): void
    {
        $jitter = new TtlJitter(new Randomizer(new Mt19937(2)));

        foreach (range(1, 200) as $_) {
            $ttl = $jitter->apply(60_000, 0.1);

            $this->assertGreaterThanOrEqual(54_000, $ttl);
            $this->assertLessThanOrEqual(66_000, $ttl);
        }
    }
}
