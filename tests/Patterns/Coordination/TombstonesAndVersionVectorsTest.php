<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\TombstonesAndVersionVectors\Replica;
use Patterns\Coordination\TombstonesAndVersionVectors\VersionVector;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class TombstonesAndVersionVectorsTest extends TestCase
{
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock;
    }

    /** @return array{Replica, Replica} two replicas that both hold user:1 = "Ada" */
    private function replicas(bool $useTombstones = true): array
    {
        $a = new Replica('A', $this->clock, $useTombstones, gcGraceMs: 10_000);
        $b = new Replica('B', $this->clock, $useTombstones, gcGraceMs: 10_000);

        $a->put('user:1', 'Ada');
        $b->syncFrom($a);

        return [$a, $b];
    }

    private function syncBothWays(Replica $a, Replica $b): void
    {
        $a->syncFrom($b);
        $b->syncFrom($a);
    }

    // ---- Tombstones ---------------------------------------------------------------------

    public function test_a_delete_that_leaves_no_trace_is_undone_by_the_next_sync(): void
    {
        [$a, $b] = $this->replicas(useTombstones: false);

        $a->delete('user:1');
        $this->assertSame([], $a->get('user:1'));

        $this->syncBothWays($a, $b);

        $this->assertSame(['Ada'], $a->get('user:1'), 'B still had it, A had "nothing", so B\'s copy was restored: zombie data.');
    }

    public function test_a_tombstone_carries_the_delete_to_the_other_replicas(): void
    {
        [$a, $b] = $this->replicas();

        $a->delete('user:1');
        $this->syncBothWays($a, $b);

        $this->assertSame([], $a->get('user:1'));
        $this->assertSame([], $b->get('user:1'), 'The tombstone is newer than B\'s value and replaced it.');
        $this->assertTrue($b->hasTombstone('user:1'), 'B does not have "nothing": it has a record of the deletion.');
    }

    public function test_tombstones_are_purged_after_the_grace_period(): void
    {
        [$a, $b] = $this->replicas();
        $a->delete('user:1');
        $this->syncBothWays($a, $b);

        $this->clock->advance(5_000);
        $this->assertSame(0, $a->purgeTombstones(), 'Too early: a replica might still need to hear about it.');

        $this->clock->advance(5_000);
        $this->assertSame(1, $a->purgeTombstones());
        $this->assertFalse($a->hasTombstone('user:1'));
    }

    public function test_a_replica_that_was_offline_longer_than_the_grace_period_resurrects_deleted_data(): void
    {
        [$a, $b] = $this->replicas();

        // B goes offline. The delete happens on A, and B never hears of it.
        $a->delete('user:1');

        // The grace period passes and A, correctly by its own rules, forgets the tombstone.
        $this->clock->advance(10_000);
        $a->purgeTombstones();

        // B comes back with its old copy.
        $this->syncBothWays($a, $b);

        $this->assertSame(['Ada'], $a->get('user:1'), 'Nothing remembers the delete any more, so the old value wins. This is why every node must be repaired within the grace period.');
    }

    // ---- Version vectors ----------------------------------------------------------------

    public function test_version_vector_comparison(): void
    {
        $base = (new VersionVector)->increment('A');           // {A:1}
        $descendant = $base->increment('A');                   // {A:2}
        $sibling = $base->increment('B');                      // {A:1, B:1}

        $this->assertTrue($descendant->dominates($base));
        $this->assertFalse($base->dominates($descendant));
        $this->assertTrue($descendant->isConcurrentWith($sibling), 'Both extend {A:1}, neither includes the other.');
        $this->assertTrue($descendant->merge($sibling)->dominates($sibling));
    }

    public function test_a_newer_version_replaces_the_one_it_was_derived_from(): void
    {
        [$a, $b] = $this->replicas();

        $a->put('user:1', 'Ada Lovelace'); // an edit made on top of the version both replicas have
        $this->syncBothWays($a, $b);

        $this->assertSame(['Ada Lovelace'], $b->get('user:1'), 'No conflict: one version clearly descends from the other.');
    }

    public function test_concurrent_writes_are_both_kept_as_siblings(): void
    {
        [$a, $b] = $this->replicas();

        // The two replicas are edited independently before they sync.
        $a->put('user:1', 'Ada Lovelace');
        $b->put('user:1', 'Ada King');

        $this->syncBothWays($a, $b);

        $this->assertEqualsCanonicalizing(['Ada Lovelace', 'Ada King'], $a->get('user:1'));
        $this->assertEqualsCanonicalizing(['Ada Lovelace', 'Ada King'], $b->get('user:1'), 'Neither edit is silently lost: the conflict is surfaced.');
    }

    public function test_a_write_made_after_seeing_the_siblings_resolves_the_conflict(): void
    {
        [$a, $b] = $this->replicas();
        $a->put('user:1', 'Ada Lovelace');
        $b->put('user:1', 'Ada King');
        $this->syncBothWays($a, $b);

        // The application reads both siblings, merges them, and writes the result.
        $a->put('user:1', 'Ada King-Lovelace');
        $this->syncBothWays($a, $b);

        $this->assertSame(['Ada King-Lovelace'], $a->get('user:1'));
        $this->assertSame(['Ada King-Lovelace'], $b->get('user:1'), 'The new version dominates both siblings everywhere.');
    }

    public function test_syncing_repeatedly_does_not_create_duplicates(): void
    {
        [$a, $b] = $this->replicas();

        foreach (range(1, 5) as $_) {
            $this->syncBothWays($a, $b);
        }

        $this->assertSame(['Ada'], $a->get('user:1'));
        $this->assertSame(['Ada'], $b->get('user:1'));
    }

    public function test_an_update_made_concurrently_with_a_delete_survives(): void
    {
        [$a, $b] = $this->replicas();

        $a->delete('user:1');
        $b->put('user:1', 'Ada Lovelace'); // B did not know about the delete

        $this->syncBothWays($a, $b);

        $this->assertSame(['Ada Lovelace'], $a->get('user:1'), 'The delete only covered the version A had seen.');
        $this->assertTrue($a->hasTombstone('user:1'), 'Both facts are kept until someone resolves them.');
    }
}
