<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\WriteAheadLog;

use Closure;

/**
 * A key-value store that keeps its data in memory and still loses nothing
 * in a crash, because every change is logged before it is applied.
 *
 * On disk, in its directory:
 *   wal.log        every change since the last checkpoint
 *   snapshot.json  the full state as of some LSN (written by checkpoint())
 *
 * Opening the store IS recovery: load the snapshot, then redo the log
 * records the snapshot does not cover yet.
 */
final class DurableKeyValueStore
{
    /** @var array<string, string> */
    private array $data = [];

    private WriteAheadLog $log;

    /** Everything up to this LSN is contained in the snapshot. */
    private int $checkpointLsn = 0;

    /** How many log records had to be redone when the store was opened. */
    public int $recordsReplayedOnRecovery = 0;

    /** Test hook: runs after a change is logged but before it is applied (simulate a crash there by throwing). */
    public ?Closure $afterLogging = null;

    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        // Recovery step 1: start from the last checkpoint, if there is one.
        if (is_file($this->snapshotPath())) {
            $snapshot = json_decode((string) file_get_contents($this->snapshotPath()), true, flags: JSON_THROW_ON_ERROR);

            $this->data = $snapshot['data'];
            $this->checkpointLsn = $snapshot['lsn'];
        }

        $this->log = new WriteAheadLog($directory.'/wal.log', firstLsn: $this->checkpointLsn + 1);

        // Recovery step 2: redo what was logged after that checkpoint.
        foreach ($this->log->replay(afterLsn: $this->checkpointLsn) as $record) {
            $this->apply($record);
            $this->recordsReplayedOnRecovery++;
        }
    }

    public function set(string $key, string $value): void
    {
        $this->change(['op' => 'set', 'key' => $key, 'value' => $value]);
    }

    public function delete(string $key): void
    {
        $this->change(['op' => 'delete', 'key' => $key]);
    }

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Persist the complete current state and empty the log.
     *
     * The snapshot is written to a temporary file and then renamed over the
     * old one. A rename is atomic, so a crash during a checkpoint leaves
     * either the old snapshot or the new one — never half of each.
     */
    public function checkpoint(): void
    {
        $lsn = $this->log->lastLsn();
        $temporary = $this->snapshotPath().'.tmp';

        file_put_contents($temporary, json_encode(['lsn' => $lsn, 'data' => $this->data], JSON_THROW_ON_ERROR));
        rename($temporary, $this->snapshotPath());

        $this->checkpointLsn = $lsn;
        $this->log->truncate(); // its contents are now redundant
    }

    public function logSizeBytes(): int
    {
        return $this->log->sizeBytes();
    }

    /** Release the file handle. (The process "crashing" is simulated by simply abandoning the object.) */
    public function close(): void
    {
        $this->log->close();
    }

    private function change(array $record): void
    {
        // 1. WRITE AHEAD: make the intent durable.
        $this->log->append($record);

        if ($this->afterLogging !== null) {
            ($this->afterLogging)();
        }

        // 2. Only now modify the data.
        $this->apply($record);
    }

    /** Applying a record twice gives the same result as applying it once, which is what makes redo safe. */
    private function apply(array $record): void
    {
        if ($record['op'] === 'set') {
            $this->data[$record['key']] = $record['value'];
        } else {
            unset($this->data[$record['key']]);
        }
    }

    private function snapshotPath(): string
    {
        return $this->directory.'/snapshot.json';
    }
}
