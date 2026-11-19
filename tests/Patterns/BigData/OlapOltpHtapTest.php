<?php

declare(strict_types=1);

namespace Tests\Patterns\BigData;

use Patterns\BigData\OlapOltpHtap\ColumnStore;
use Patterns\BigData\OlapOltpHtap\HtapStore;
use Patterns\BigData\OlapOltpHtap\RowStore;
use PHPUnit\Framework\TestCase;

final class OlapOltpHtapTest extends TestCase
{
    private const ROWS = 1_000;

    /** An order with eight columns. Sorted by country, as a warehouse would cluster it. */
    private function order(int $id): array
    {
        return [
            'id' => $id,
            'customer' => "customer-{$id}",
            'country' => ['DE', 'NG', 'UK', 'US'][intdiv(($id - 1) * 4, self::ROWS)],
            'status' => $id % 10 === 0 ? 'refunded' : 'paid',
            'amount' => 10 + $id % 7,
            'quantity' => 1 + $id % 3,
            'created_at' => '2026-11-12',
            'note' => 'gift wrap, leave at the door',
        ];
    }

    /** @return array{RowStore, ColumnStore} */
    private function loaded(): array
    {
        $rows = new RowStore;
        $columns = new ColumnStore;

        for ($id = 1; $id <= self::ROWS; $id++) {
            $rows->insert($id, $this->order($id));
            $columns->insert($id, $this->order($id));
        }

        $rows->seeks = $rows->cellsRead = $columns->seeks = $columns->cellsRead = 0;

        return [$rows, $columns];
    }

    // ---- The transactional workload -----------------------------------------------------

    public function test_a_point_lookup_is_one_read_in_a_row_store_and_one_per_column_in_a_column_store(): void
    {
        [$rows, $columns] = $this->loaded();

        $this->assertSame($rows->find(500), $columns->find(500), 'Same answer...');

        $this->assertSame(1, $rows->seeks, '...but the row store went to one place,');
        $this->assertSame(8, $columns->seeks, 'while the column store had to visit eight.');
    }

    public function test_inserting_a_row_is_one_write_in_a_row_store_and_one_per_column_in_a_column_store(): void
    {
        $rows = new RowStore;
        $columns = new ColumnStore;

        $rows->insert(1, $this->order(1));
        $columns->insert(1, $this->order(1));

        $this->assertSame(1, $rows->seeks);
        $this->assertSame(8, $columns->seeks, 'Which is why column stores prefer bulk loads to single-row inserts.');
    }

    // ---- The analytical workload --------------------------------------------------------

    public function test_an_aggregate_reads_one_column_in_a_column_store_and_everything_in_a_row_store(): void
    {
        [$rows, $columns] = $this->loaded();

        $this->assertSame($rows->sum('amount'), $columns->sum('amount'), 'Same answer again.');

        $this->assertSame(8_000, $rows->cellsRead, 'The row store loaded all 8 columns of all 1,000 rows to add up one of them.');
        $this->assertSame(1_000, $columns->cellsRead, 'The column store loaded the one column it needed: 8x less.');
    }

    public function test_the_gap_grows_with_the_width_of_the_table(): void
    {
        $rows = new RowStore;
        $columns = new ColumnStore;

        // A typical analytics table: 100 columns, of which the query uses one.
        $wide = array_fill_keys(array_map(fn (int $i) => "metric_{$i}", range(1, 100)), 1);

        foreach (range(1, 200) as $id) {
            $rows->insert($id, $wide);
            $columns->insert($id, $wide);
        }

        $rows->sum('metric_42');
        $columns->sum('metric_42');

        $this->assertSame(100, intdiv($rows->cellsRead, $columns->cellsRead), '100x less data to read.');
    }

    public function test_columns_compress_well_because_similar_values_sit_together(): void
    {
        [, $columns] = $this->loaded();

        $this->assertSame(
            [['value' => 'DE', 'run' => 250], ['value' => 'NG', 'run' => 250], ['value' => 'UK', 'run' => 250], ['value' => 'US', 'run' => 250]],
            $columns->runLengthEncode('country'),
            'A thousand values stored as four pairs.'
        );
    }

    public function test_dictionary_encoding_stores_each_distinct_value_once(): void
    {
        [, $columns] = $this->loaded();

        $encoded = $columns->dictionaryEncode('status');

        $this->assertSame(['paid', 'refunded'], $encoded['dictionary']);
        $this->assertCount(1_000, $encoded['codes']);
        $this->assertSame([0, 1], array_values(array_unique($encoded['codes'])), 'Each row now holds a 1-bit code instead of a string.');
    }

    // ---- HTAP ---------------------------------------------------------------------------

    public function test_htap_serves_transactions_from_rows_and_analytics_from_columns(): void
    {
        $htap = new HtapStore;

        foreach (range(1, 100) as $id) {
            $htap->insert($id, $this->order($id));
        }

        $htap->mergeDelta();
        $htap->rows->seeks = $htap->rows->cellsRead = $htap->columns->cellsRead = 0;

        $htap->find(42);
        $this->assertSame(1, $htap->rows->seeks, 'The lookup used the row store.');

        $htap->sum('amount');
        $this->assertSame(100, $htap->columns->cellsRead, 'The aggregate used the column store...');
        $this->assertSame(8, $htap->rows->cellsRead, '...and did not scan the row store at all (those 8 cells are the lookup above).');
    }

    public function test_htap_analytics_include_transactions_that_committed_a_moment_ago(): void
    {
        $htap = new HtapStore;
        $htap->insert(1, ['amount' => 50]);
        $htap->mergeDelta();

        $htap->insert(2, ['amount' => 30]); // just committed; not yet merged into the column store

        $this->assertSame(1, $htap->pendingDelta());
        $this->assertSame(50, $htap->sum('amount', includeDelta: false), 'A warehouse fed by periodic ETL would still say 50.');
        $this->assertSame(80, $htap->sum('amount'), 'HTAP reads column store + delta: fresh.');

        $htap->mergeDelta();

        $this->assertSame(0, $htap->pendingDelta());
        $this->assertSame(80, $htap->sum('amount'), 'Merging changes where the data lives, not the answer.');
    }
}
