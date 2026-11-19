<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\Sharding\DirectorySharding;
use Patterns\DataArchitecture\Sharding\HashSharding;
use Patterns\DataArchitecture\Sharding\RangeSharding;
use Patterns\DataArchitecture\Sharding\ShardedDatabase;
use PHPUnit\Framework\TestCase;

final class ShardingTest extends TestCase
{
    private const THREE_SHARDS = ['shard-0', 'shard-1', 'shard-2'];

    private function alphabetRanges(): RangeSharding
    {
        return new RangeSharding(['i' => 'shard-0', 'r' => 'shard-1', '~' => 'shard-2']); // a-h, i-q, r-z
    }

    // ---- Routing ------------------------------------------------------------------------

    public function test_a_lookup_by_shard_key_goes_to_exactly_one_shard(): void
    {
        $db = new ShardedDatabase(new HashSharding(self::THREE_SHARDS), self::THREE_SHARDS);

        $shard = $db->put('user-42', ['name' => 'Ada', 'country' => 'UK']);

        $this->assertContains($shard, self::THREE_SHARDS);
        $this->assertSame('Ada', $db->get('user-42')['name']);
        $this->assertSame(1, $db->shardsContacted);
    }

    public function test_a_query_without_the_shard_key_has_to_ask_every_shard(): void
    {
        $db = new ShardedDatabase(new HashSharding(self::THREE_SHARDS), self::THREE_SHARDS);

        foreach (range(1, 30) as $i) {
            $db->put("user-{$i}", ['name' => "User {$i}", 'country' => $i % 10 === 0 ? 'NG' : 'UK']);
        }

        $nigerians = $db->scatterGather(fn (array $row) => $row['country'] === 'NG');

        $this->assertCount(3, $nigerians);
        $this->assertSame(3, $db->shardsContacted, 'Country is not the shard key: nobody knows which shard holds them.');
    }

    // ---- Hash sharding ------------------------------------------------------------------

    public function test_hash_sharding_spreads_sequential_keys_evenly(): void
    {
        $db = new ShardedDatabase(new HashSharding(self::THREE_SHARDS), self::THREE_SHARDS);

        foreach (range(1, 3_000) as $i) {
            $db->put("user-{$i}", []);
        }

        foreach ($db->sizes() as $size) {
            $this->assertEqualsWithDelta(1_000, $size, 120, 'Each shard holds about a third.');
        }
    }

    public function test_adding_a_shard_under_modulo_hashing_moves_most_of_the_data(): void
    {
        $db = new ShardedDatabase(new HashSharding(self::THREE_SHARDS), self::THREE_SHARDS);

        foreach (range(1, 2_000) as $i) {
            $db->put("user-{$i}", []);
        }

        $fourShards = [...self::THREE_SHARDS, 'shard-3'];
        $moved = $db->reshard(new HashSharding($fourShards), $fourShards);

        $this->assertGreaterThan(1_300, $moved, 'About three quarters of all rows changed shard to add 33% capacity.');
        $this->assertNotNull($db->get('user-1'), 'After the migration every row is still reachable.');
    }

    // ---- Range sharding -----------------------------------------------------------------

    public function test_range_sharding_keeps_neighbouring_keys_on_the_same_shard(): void
    {
        $strategy = $this->alphabetRanges();
        $db = new ShardedDatabase($strategy, self::THREE_SHARDS);

        foreach (['ada', 'bob', 'dan', 'eve', 'ian', 'kim', 'sam', 'zoe'] as $name) {
            $db->put($name, ['name' => $name]);
        }

        $this->assertSame(['shard-0'], $strategy->shardsForRange('d', 'f'));

        $found = $db->queryShards(
            $strategy->shardsForRange('d', 'f'),
            fn (array $row, string $key) => $key >= 'd' && $key <= 'f',
        );

        $this->assertSame(['dan', 'eve'], array_keys($found));
        $this->assertSame(1, $db->shardsContacted, 'A range scan that would cost three shards with hashing costs one here.');
    }

    public function test_a_range_spanning_a_boundary_touches_only_the_shards_it_overlaps(): void
    {
        $this->assertSame(['shard-0', 'shard-1'], $this->alphabetRanges()->shardsForRange('g', 'k'));
        $this->assertSame(self::THREE_SHARDS, $this->alphabetRanges()->shardsForRange('a', 'z'));
    }

    public function test_range_sharding_creates_hot_shards_when_keys_are_skewed(): void
    {
        // Shard by order date: one shard per year.
        $byYear = new RangeSharding(['2025' => 'shard-2024', '2026' => 'shard-2025', '9999' => 'shard-2026']);
        $db = new ShardedDatabase($byYear, ['shard-2024', 'shard-2025', 'shard-2026']);

        // All of today's orders are, naturally, dated today.
        foreach (range(1, 500) as $i) {
            $db->put(sprintf('2026-11-12#%04d', $i), []);
        }

        $this->assertSame(['shard-2024' => 0, 'shard-2025' => 0, 'shard-2026' => 500], $db->sizes(), 'Every new write lands on the newest shard.');
    }

    // ---- Directory sharding -------------------------------------------------------------

    public function test_directory_sharding_can_move_a_single_tenant_without_touching_the_others(): void
    {
        $directory = new DirectorySharding(defaultShard: 'shared-shard');
        $db = new ShardedDatabase($directory, ['shared-shard', 'dedicated-shard']);

        foreach (['acme', 'globex', 'initech'] as $tenant) {
            $db->put($tenant, ['tenant' => $tenant]);
        }

        // Globex has grown and gets a shard of its own.
        $directory->assign('globex', 'dedicated-shard');
        $moved = $db->reshard($directory, ['shared-shard', 'dedicated-shard']);

        $this->assertSame(1, $moved, 'Only the row that was reassigned moves.');
        $this->assertSame(['shared-shard' => 2, 'dedicated-shard' => 1], $db->sizes());
        $this->assertSame('globex', $db->get('globex')['tenant']);
    }
}
