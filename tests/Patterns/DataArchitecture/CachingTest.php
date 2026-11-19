<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\Caching\Cache;
use Patterns\DataArchitecture\Caching\CacheAside;
use Patterns\DataArchitecture\Caching\Database;
use Patterns\DataArchitecture\Caching\RefreshAhead;
use Patterns\DataArchitecture\Caching\WriteBehind;
use Patterns\DataArchitecture\Caching\WriteThrough;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class CachingTest extends TestCase
{
    private FakeClock $clock;

    private Database $database;

    private Cache $cache;

    protected function setUp(): void
    {
        $this->clock = new FakeClock;
        $this->database = new Database;
        $this->cache = new Cache($this->clock);
    }

    // ---- Cache-aside --------------------------------------------------------------------

    public function test_cache_aside_loads_on_the_first_read_and_serves_from_memory_afterwards(): void
    {
        $this->database->write('product:1', 'Keyboard');
        $products = new CacheAside($this->database, $this->cache);

        $this->assertSame('Keyboard', $products->get('product:1')); // miss: goes to the database
        $this->assertSame('Keyboard', $products->get('product:1')); // hit
        $this->assertSame('Keyboard', $products->get('product:1')); // hit

        $this->assertSame(1, $this->database->reads, 'Three reads, one query.');
        $this->assertSame([2, 1], [$this->cache->hits, $this->cache->misses]);
    }

    public function test_cache_aside_invalidates_on_write_so_the_next_read_reloads(): void
    {
        $this->database->write('product:1', 'Keyboard');
        $products = new CacheAside($this->database, $this->cache);
        $products->get('product:1');

        $products->update('product:1', 'Mechanical Keyboard');

        $this->assertFalse($this->cache->has('product:1'), 'The entry was deleted, not overwritten.');
        $this->assertSame('Mechanical Keyboard', $products->get('product:1'));
        $this->assertSame(2, $this->database->reads, 'That read was a miss again: the price of invalidation.');
    }

    public function test_cache_aside_serves_stale_data_when_the_database_changes_behind_its_back(): void
    {
        $this->database->write('price:1', 100);
        $prices = new CacheAside($this->database, $this->cache, ttlMs: 60_000);
        $prices->get('price:1');

        // Another service updates the row directly. Nobody invalidates our cache.
        $this->database->write('price:1', 80);

        $this->assertSame(100, $prices->get('price:1'), 'Stale...');

        $this->clock->advanceSeconds(61);

        $this->assertSame(80, $prices->get('price:1'), '...until the TTL expires. The TTL is the safety net.');
    }

    // ---- Write-through ------------------------------------------------------------------

    public function test_write_through_updates_cache_and_database_together(): void
    {
        $profiles = new WriteThrough($this->database, $this->cache);

        $profiles->put('user:1', 'Ada');

        $this->assertSame('Ada', $this->database->peek('user:1'));
        $this->assertSame('Ada', $profiles->get('user:1'));
        $this->assertSame(0, $this->database->reads, 'The read after a write is a guaranteed hit.');
    }

    public function test_write_through_never_serves_an_old_value(): void
    {
        $profiles = new WriteThrough($this->database, $this->cache);

        $profiles->put('user:1', 'Ada');
        $profiles->put('user:1', 'Ada Lovelace');

        $this->assertSame('Ada Lovelace', $profiles->get('user:1'));
        $this->assertSame(2, $this->database->writes, 'Every write reaches the database immediately.');
    }

    // ---- Write-behind -------------------------------------------------------------------

    public function test_write_behind_acknowledges_writes_before_the_database_has_them(): void
    {
        $counters = new WriteBehind($this->database, $this->cache);

        $counters->put('views:home', 1);

        $this->assertSame(1, $counters->get('views:home'), 'Readers see the new value at once...');
        $this->assertSame(0, $this->database->writes, '...but the database has not been touched.');
        $this->assertSame(1, $counters->pendingWrites());

        $counters->flush();

        $this->assertSame(1, $this->database->peek('views:home'));
    }

    public function test_write_behind_collapses_many_writes_into_one(): void
    {
        $counters = new WriteBehind($this->database, $this->cache);

        foreach (range(1, 100) as $views) {
            $counters->put('views:home', $views);
        }

        $this->assertSame(1, $counters->flush());
        $this->assertSame(1, $this->database->writes, 'A hundred updates, one database write.');
        $this->assertSame(100, $this->database->peek('views:home'));
    }

    public function test_write_behind_loses_unflushed_writes_in_a_crash(): void
    {
        $counters = new WriteBehind($this->database, $this->cache);
        $counters->put('views:home', 41);
        $counters->flush();

        $counters->put('views:home', 42); // acknowledged to the caller
        $counters->crash();

        $this->assertSame(41, $this->database->peek('views:home'), 'The acknowledged write is gone. Only use this for data you can afford to lose.');
    }

    // ---- Refresh-ahead ------------------------------------------------------------------

    public function test_refresh_ahead_reloads_a_popular_key_before_it_expires(): void
    {
        $this->database->write('rates:eur', 1.08);
        $rates = new RefreshAhead($this->database, $this->cache, ttlMs: 60_000, refreshWindow: 0.2);

        $rates->get('rates:eur');                 // loaded; expires at t = 60s
        $this->clock->advanceSeconds(50);         // 10s left: inside the last 20%
        $this->database->write('rates:eur', 1.09);

        $this->assertSame(1.08, $rates->get('rates:eur'), 'The reader is answered immediately with the current entry.');

        $rates->runBackgroundRefreshes();

        $this->clock->advanceSeconds(20);         // t = 70s: the ORIGINAL entry would have expired by now
        $missesBefore = $this->cache->misses;

        $this->assertSame(1.09, $rates->get('rates:eur'));
        $this->assertSame($missesBefore, $this->cache->misses, 'No reader ever hit a miss for this key.');
        $this->assertSame(1, $rates->backgroundRefreshes);
    }

    public function test_refresh_ahead_leaves_fresh_and_unpopular_keys_alone(): void
    {
        $this->database->write('rates:eur', 1.08);
        $this->database->write('rates:xyz', 0.01);
        $rates = new RefreshAhead($this->database, $this->cache, ttlMs: 60_000, refreshWindow: 0.2);

        $rates->get('rates:eur');
        $rates->get('rates:xyz');

        $this->clock->advanceSeconds(10);
        $rates->get('rates:eur'); // still fresh: nothing to do
        $rates->runBackgroundRefreshes();
        $this->assertSame(0, $rates->backgroundRefreshes);

        // Nobody asks for rates:xyz again, so it simply expires.
        $this->clock->advanceSeconds(60);
        $this->assertFalse($this->cache->has('rates:xyz'));
    }
}
