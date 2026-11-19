<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\ChangeDataCapture\CdcConnector;
use Patterns\DataArchitecture\ChangeDataCapture\ChangeEvent;
use Patterns\DataArchitecture\ChangeDataCapture\Database;
use Patterns\DataArchitecture\ChangeDataCapture\TableReplica;
use Patterns\DataArchitecture\ChangeDataCapture\Transaction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChangeDataCaptureTest extends TestCase
{
    public function test_every_committed_change_is_logged_with_before_and_after_images(): void
    {
        $db = new Database;

        $db->transaction(fn (Transaction $tx) => $tx->insert('users', 1, ['name' => 'Ada', 'plan' => 'free']));
        $db->transaction(fn (Transaction $tx) => $tx->update('users', 1, ['plan' => 'pro']));
        $db->transaction(fn (Transaction $tx) => $tx->delete('users', 1));

        [$insert, $update, $delete] = $db->logAfter(0);

        $this->assertSame([ChangeEvent::CREATE, null, ['name' => 'Ada', 'plan' => 'free']], [$insert->op, $insert->before, $insert->after]);
        $this->assertSame(ChangeEvent::UPDATE, $update->op);
        $this->assertSame('free', $update->before['plan']);
        $this->assertSame('pro', $update->after['plan']);
        $this->assertSame([ChangeEvent::DELETE, ['name' => 'Ada', 'plan' => 'pro'], null], [$delete->op, $delete->before, $delete->after]);

        $this->assertSame([1, 2, 3], [$insert->lsn, $update->lsn, $delete->lsn], 'Positions only ever increase.');
    }

    public function test_a_rolled_back_transaction_leaves_no_trace_in_the_log(): void
    {
        $db = new Database;

        try {
            $db->transaction(function (Transaction $tx) {
                $tx->insert('users', 1, ['name' => 'Ada']);

                throw new RuntimeException('something went wrong');
            });
        } catch (RuntimeException) {
            // rolled back
        }

        $this->assertNull($db->find('users', 1));
        $this->assertSame([], $db->logAfter(0), 'CDC can never emit data that was not committed.');
    }

    public function test_changes_made_in_one_transaction_stay_together_and_in_order(): void
    {
        $db = new Database;

        $db->transaction(function (Transaction $tx) {
            $tx->insert('orders', 'o-1', ['total' => 50]);
            $tx->insert('order_lines', 'l-1', ['order' => 'o-1', 'sku' => 'book']);
        });

        [$first, $second] = $db->logAfter(0);

        $this->assertSame($first->transactionId, $second->transactionId);
        $this->assertSame(['orders', 'order_lines'], [$first->table, $second->table]);
    }

    public function test_a_connector_keeps_a_derived_store_in_sync_without_the_application_knowing(): void
    {
        $db = new Database;
        $searchIndex = new TableReplica('products');
        $connector = new CdcConnector($db);
        $connector->addSink($searchIndex->apply(...));

        // The application only ever talks to its own database.
        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p1', ['name' => 'Laptop', 'price' => 900]));
        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p2', ['name' => 'Mouse', 'price' => 20]));
        $db->transaction(fn (Transaction $tx) => $tx->update('products', 'p1', ['price' => 850]));
        $db->transaction(fn (Transaction $tx) => $tx->delete('products', 'p2'));

        $this->assertSame([], $searchIndex->rows(), 'Asynchronous: nothing has been shipped yet.');

        $this->assertSame(4, $connector->poll());
        $this->assertSame($db->rows('products'), $searchIndex->rows(), 'Including the delete, which polling on updated_at would miss.');
    }

    public function test_a_snapshot_captures_existing_rows_before_streaming_continues(): void
    {
        $db = new Database;
        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p1', ['name' => 'Laptop']));
        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p2', ['name' => 'Mouse']));

        // The connector is installed long after the data was written.
        $ops = [];
        $replica = new TableReplica('products');
        $connector = new CdcConnector($db);
        $connector->addSink($replica->apply(...));
        $connector->addSink(function (ChangeEvent $event) use (&$ops) {
            $ops[] = $event->op;
        });

        $this->assertSame(2, $connector->snapshot());

        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p3', ['name' => 'Keyboard']));
        $connector->poll();

        $this->assertSame(['r', 'r', 'c'], $ops, 'Two snapshot reads, then the live change. Nothing twice, nothing skipped.');
        $this->assertSame($db->rows('products'), $replica->rows());
    }

    public function test_a_restarted_connector_resumes_from_its_saved_offset(): void
    {
        $db = new Database;
        $replica = new TableReplica('products');

        $connector = new CdcConnector($db);
        $connector->addSink($replica->apply(...));

        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p1', ['name' => 'Laptop']));
        $connector->poll();
        $savedOffset = $connector->offset();

        // The connector is down while the application keeps writing.
        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p2', ['name' => 'Mouse']));
        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p3', ['name' => 'Keyboard']));

        $restarted = new CdcConnector($db, offset: $savedOffset);
        $restarted->addSink($replica->apply(...));

        $this->assertSame(2, $restarted->poll(), 'Exactly the two changes it missed.');
        $this->assertSame($db->rows('products'), $replica->rows());
    }

    public function test_a_failing_sink_causes_redelivery_not_loss(): void
    {
        $db = new Database;
        $received = [];
        $failures = 1;

        $connector = new CdcConnector($db);
        $connector->addSink(function (ChangeEvent $event) use (&$received, &$failures) {
            $received[] = $event->key;

            if ($failures-- > 0) {
                throw new RuntimeException('sink unavailable');
            }
        });

        $db->transaction(fn (Transaction $tx) => $tx->insert('products', 'p1', ['name' => 'Laptop']));

        try {
            $connector->poll();
        } catch (RuntimeException) {
            // delivery failed, so the offset did not move
        }

        $this->assertSame(0, $connector->offset());

        $connector->poll();

        $this->assertSame(['p1', 'p1'], $received, 'At-least-once: the sink saw the event twice and must tolerate that.');
        $this->assertSame(1, $connector->offset());
    }

    public function test_a_connector_can_capture_a_subset_of_tables(): void
    {
        $db = new Database;
        $tables = [];

        $connector = new CdcConnector($db, tables: ['orders']);
        $connector->addSink(function (ChangeEvent $event) use (&$tables) {
            $tables[] = $event->table;
        });

        $db->transaction(fn (Transaction $tx) => $tx->insert('orders', 1, ['total' => 50]));
        $db->transaction(fn (Transaction $tx) => $tx->insert('sessions', 'abc', ['user' => 1]));

        $this->assertSame(1, $connector->poll());
        $this->assertSame(['orders'], $tables);
        $this->assertSame(2, $connector->offset(), 'Skipped entries still advance the offset.');
    }
}
