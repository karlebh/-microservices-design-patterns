<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\DataLakehouse\ConcurrentCommitException;
use Patterns\DataArchitecture\DataLakehouse\LakehouseTable;
use Patterns\DataArchitecture\DataLakehouse\ObjectStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DataLakehouseTest extends TestCase
{
    private ObjectStore $store;

    private LakehouseTable $sales;

    protected function setUp(): void
    {
        $this->store = new ObjectStore;
        $this->sales = new LakehouseTable($this->store, 'sales');
    }

    /** Three daily loads, one data file each. */
    private function loadThreeDays(): void
    {
        $this->sales->append([
            ['day' => '2026-11-01', 'sku' => 'book', 'amount' => 45],
            ['day' => '2026-11-01', 'sku' => 'pen', 'amount' => 3],
        ]);
        $this->sales->append([
            ['day' => '2026-11-02', 'sku' => 'book', 'amount' => 45],
            ['day' => '2026-11-02', 'sku' => 'lamp', 'amount' => 30],
        ]);
        $this->sales->append([
            ['day' => '2026-11-03', 'sku' => 'pen', 'amount' => 3],
        ]);
    }

    // ---- The table is its log -----------------------------------------------------------

    public function test_every_write_adds_an_immutable_file_and_a_commit(): void
    {
        $this->loadThreeDays();

        $this->assertSame(3, $this->sales->version());
        $this->assertCount(3, $this->store->list('sales/data/'));
        $this->assertCount(3, $this->store->list('sales/_log/'));
        $this->assertCount(5, $this->sales->scan());
    }

    public function test_time_travel_reads_the_table_as_it_was(): void
    {
        $this->loadThreeDays();

        $this->assertCount(2, $this->sales->scan(asOfVersion: 1));
        $this->assertCount(4, $this->sales->scan(asOfVersion: 2));
        $this->assertCount(5, $this->sales->scan(), 'Old versions stay intact because files are never modified.');
    }

    public function test_an_uncommitted_write_is_invisible_to_readers(): void
    {
        $this->loadThreeDays();

        // A job writes a data file and crashes before committing.
        $this->store->putIfAbsent('sales/data/part-99999.json', json_encode([['day' => '2026-11-04', 'sku' => 'junk', 'amount' => 1]]));

        $this->assertCount(5, $this->sales->scan(), 'The orphan file is not in the log, so it is not in the table.');
    }

    // ---- Optimistic concurrency ---------------------------------------------------------

    public function test_two_writers_racing_for_the_same_version_cannot_both_win(): void
    {
        $this->loadThreeDays();
        $seenByBothWriters = $this->sales->version();

        $this->sales->append([['day' => '2026-11-04', 'sku' => 'book', 'amount' => 45]], basedOnVersion: $seenByBothWriters);

        try {
            $this->sales->append([['day' => '2026-11-04', 'sku' => 'pen', 'amount' => 3]], basedOnVersion: $seenByBothWriters);
            $this->fail('The second writer must be told that it lost the race.');
        } catch (ConcurrentCommitException) {
            // It re-reads the table and tries again on top of the winner's version.
            $this->sales->append([['day' => '2026-11-04', 'sku' => 'pen', 'amount' => 3]]);
        }

        $this->assertSame(5, $this->sales->version());
        $this->assertCount(7, $this->sales->scan(), 'Both appends are in, neither overwrote the other.');
    }

    // ---- Data skipping ------------------------------------------------------------------

    public function test_file_statistics_let_a_query_skip_files_that_cannot_match(): void
    {
        $this->loadThreeDays();

        $rows = $this->sales->scan(where: ['day' => '2026-11-02']);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $this->sales->filesScanned, 'Only the file whose min/max range covers that day was opened.');
        $this->assertSame(2, $this->sales->filesSkipped);
    }

    public function test_statistics_cannot_rule_out_a_file_when_the_value_lies_inside_its_range(): void
    {
        $this->loadThreeDays();

        $rows = $this->sales->scan(where: ['sku' => 'lamp']);

        // Day 1 holds [book, pen]. "lamp" sorts between them, so the file MIGHT
        // contain one and has to be opened, although it does not.
        $this->assertCount(1, $rows);
        $this->assertSame(2, $this->sales->filesScanned, 'Min/max only helps when the data is sorted or clustered on the filtered column.');
        $this->assertSame(1, $this->sales->filesSkipped);
    }

    // ---- Schema evolution ---------------------------------------------------------------

    public function test_adding_a_column_does_not_rewrite_any_data(): void
    {
        $this->loadThreeDays();
        $filesBefore = $this->sales->dataFiles();

        $this->sales->addColumn('currency', 'USD');
        $this->sales->append([['day' => '2026-11-04', 'sku' => 'book', 'amount' => 40, 'currency' => 'EUR']]);

        $currencies = array_column($this->sales->scan(), 'currency');

        $this->assertSame(['USD', 'USD', 'USD', 'USD', 'USD', 'EUR'], $currencies, 'Old rows get the default at read time.');
        $this->assertSame($filesBefore, array_slice($this->sales->dataFiles(), 0, 3), 'The old files are byte-for-byte the same.');
        $this->assertArrayNotHasKey('currency', $this->sales->scan(asOfVersion: 3)[0], 'And the old version still has the old schema.');
    }

    // ---- Deletes: copy-on-write vs merge-on-read ----------------------------------------

    public function test_copy_on_write_rewrites_the_files_that_contain_deleted_rows(): void
    {
        $this->loadThreeDays();

        $this->sales->delete(fn (array $row) => $row['sku'] === 'lamp');

        $this->assertCount(4, $this->sales->scan());
        $this->assertSame(1, $this->sales->rowsRewritten, 'To drop one row, the other row of that file was written again.');
        $this->assertCount(4, $this->store->list('sales/data/'), 'The old file still exists for time travel...');
        $this->assertCount(5, $this->sales->scan(asOfVersion: 3), '...and the lamp is still there in the previous version.');
    }

    public function test_merge_on_read_records_the_delete_and_leaves_the_data_alone(): void
    {
        $this->loadThreeDays();

        $this->sales->delete(fn (array $row) => $row['sku'] === 'lamp', mergeOnRead: true);

        $this->assertSame(0, $this->sales->rowsRewritten, 'Nothing was rewritten: the write is cheap.');
        $this->assertCount(3, $this->store->list('sales/data/'));
        $this->assertCount(4, $this->sales->scan(), 'Readers filter the deleted row out themselves.');
    }

    public function test_compaction_folds_pending_deletes_into_new_files(): void
    {
        $this->loadThreeDays();
        $this->sales->delete(fn (array $row) => $row['sku'] === 'pen', mergeOnRead: true);

        $this->sales->compact();

        $this->assertCount(1, $this->sales->dataFiles(), 'Many small files became one.');
        $this->assertSame(['book', 'book', 'lamp'], array_column($this->sales->scan(), 'sku'));
        $this->assertSame(3, $this->sales->rowsRewritten);
    }

    // ---- Cleaning up --------------------------------------------------------------------

    public function test_vacuum_reclaims_storage_and_ends_time_travel_to_older_versions(): void
    {
        $this->loadThreeDays();
        $compacted = $this->sales->compact(); // version 4: one new file replaces three

        $this->assertCount(4, $this->store->list('sales/data/'));

        $this->assertSame(3, $this->sales->vacuum(keepFromVersion: $compacted));
        $this->assertCount(1, $this->store->list('sales/data/'));
        $this->assertCount(5, $this->sales->scan(), 'The current version is unaffected.');

        $this->expectException(RuntimeException::class);
        $this->sales->scan(asOfVersion: 2);
    }
}
