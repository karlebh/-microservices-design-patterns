<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\WriteAheadLog\DurableKeyValueStore;
use Patterns\DataArchitecture\WriteAheadLog\WriteAheadLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WriteAheadLogTest extends TestCase
{
    private string $directory;

    /** @var list<DurableKeyValueStore|WriteAheadLog> everything opened, so the files can be released afterwards */
    private array $opened = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'patterns-wal-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->opened as $resource) {
            $resource->close();
        }

        $this->opened = [];

        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    /** Open the store. Calling it again later simulates the process restarting after a crash. */
    private function start(): DurableKeyValueStore
    {
        return $this->opened[] = new DurableKeyValueStore($this->directory);
    }

    private function logPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'wal.log';
    }

    // ---- Durability and recovery --------------------------------------------------------

    public function test_data_survives_a_crash_because_the_log_is_replayed(): void
    {
        $store = $this->start();
        $store->set('balance:ada', '100');
        $store->set('balance:bob', '50');
        $store->set('balance:ada', '70');
        $store->delete('balance:bob');

        // The process dies. Its memory is gone; nothing was ever written except the log.
        unset($store);

        $recovered = $this->start();

        $this->assertSame('70', $recovered->get('balance:ada'), 'Changes are redone in their original order.');
        $this->assertNull($recovered->get('balance:bob'));
        $this->assertSame(4, $recovered->recordsReplayedOnRecovery);
    }

    public function test_a_change_is_durable_before_it_is_applied(): void
    {
        $store = $this->start();
        $store->afterLogging = fn () => throw new RuntimeException('power failure');

        try {
            $store->set('order:1', 'paid');
        } catch (RuntimeException) {
            // crashed after logging, before touching the data
        }

        $this->assertNull($store->get('order:1'), 'The running process never got to apply it...');
        $this->assertSame('paid', $this->start()->get('order:1'), '...but it was already in the log, so recovery completes it.');
    }

    public function test_recovering_twice_gives_the_same_state(): void
    {
        $store = $this->start();
        $store->set('counter', '1');
        $store->set('counter', '2');

        $this->start();              // one recovery...
        $again = $this->start();     // ...and another one, replaying the same records

        $this->assertSame('2', $again->get('counter'), 'Redo is idempotent.');
    }

    // ---- Checkpoints --------------------------------------------------------------------

    public function test_a_checkpoint_empties_the_log_and_shortens_recovery(): void
    {
        $store = $this->start();

        foreach (range(1, 20) as $i) {
            $store->set("key:{$i}", "value {$i}");
        }

        $this->assertGreaterThan(0, $store->logSizeBytes());

        $store->checkpoint();
        $this->assertSame(0, $store->logSizeBytes(), 'The snapshot now holds everything the log did.');

        $store->set('key:21', 'value 21');

        $recovered = $this->start();

        $this->assertSame(1, $recovered->recordsReplayedOnRecovery, 'Only the change made after the checkpoint is redone.');
        $this->assertSame('value 10', $recovered->get('key:10'), 'From the snapshot.');
        $this->assertSame('value 21', $recovered->get('key:21'), 'From the log.');
    }

    public function test_sequence_numbers_keep_increasing_across_checkpoints_and_restarts(): void
    {
        $log = $this->opened[] = new WriteAheadLog($this->logPath());

        $this->assertSame([1, 2], [$log->append(['op' => 'a']), $log->append(['op' => 'b'])]);

        $log->truncate();
        $this->assertSame(3, $log->append(['op' => 'c']), 'Truncating the file does not reset the numbering.');

        $reopened = $this->opened[] = new WriteAheadLog($this->logPath());
        $this->assertSame(4, $reopened->append(['op' => 'd']), 'Neither does a restart.');
    }

    // ---- Damaged logs -------------------------------------------------------------------

    public function test_a_record_cut_short_by_a_crash_is_ignored_and_trimmed(): void
    {
        $store = $this->start();
        $store->set('a', '1');
        $store->set('b', '2');
        $store->set('c', '3');
        $store->close();

        // The machine lost power while the last record was being written:
        // only part of it reached the disk.
        $bytes = file_get_contents($this->logPath());
        file_put_contents($this->logPath(), substr($bytes, 0, -7));

        $recovered = $this->start();

        $this->assertSame('2', $recovered->get('b'), 'Complete records are recovered.');
        $this->assertNull($recovered->get('c'), 'The torn record is treated as if it never happened (the client never got an "ok" for it).');

        // The torn bytes were removed, so new writes are appended to a clean log.
        $recovered->set('d', '4');
        $this->assertSame('4', $this->start()->get('d'));
    }

    public function test_corruption_is_detected_by_the_checksum_instead_of_being_applied(): void
    {
        $store = $this->start();
        $store->set('a', 'first');
        $store->set('b', 'second');
        $store->close();

        // A bit flips on disk inside the second record's payload.
        $bytes = file_get_contents($this->logPath());
        $position = strpos($bytes, 'second');
        $bytes[$position] = 'X';
        file_put_contents($this->logPath(), $bytes);

        $recovered = $this->start();

        $this->assertSame('first', $recovered->get('a'));
        $this->assertNull($recovered->get('b'), 'A wrong value ("Xecond") is never served: the record fails its CRC and is rejected.');
    }

    public function test_an_empty_or_missing_log_is_a_valid_starting_point(): void
    {
        $store = $this->start();

        $this->assertNull($store->get('anything'));
        $this->assertSame(0, $store->recordsReplayedOnRecovery);
    }
}
