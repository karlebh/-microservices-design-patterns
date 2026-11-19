<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\DataLakehouse;

use Closure;
use RuntimeException;

/**
 * A table format over an object store, in the style of Delta Lake.
 *
 * The table IS its transaction log. Each commit is one object,
 * `<table>/_log/<version>.json`, holding a list of actions:
 *
 *   add          a new immutable data file (with row count and min/max statistics)
 *   remove       a data file that is no longer part of the table
 *   delete-rows  positions inside a data file that are deleted (merge-on-read)
 *   add-column   a schema change, with the default for rows written before it
 *
 * The state at version N is what you get by replaying commits 1..N.
 */
final class LakehouseTable
{
    /** Data files a scan opened / was able to skip thanks to statistics. */
    public int $filesScanned = 0;

    public int $filesSkipped = 0;

    /** Rows that had to be written again by copy-on-write deletes and compaction. */
    public int $rowsRewritten = 0;

    private int $fileSequence = 0;

    public function __construct(
        private readonly ObjectStore $store,
        private readonly string $name,
    ) {}

    // ---- Writing -----------------------------------------------------------------------

    /**
     * Add rows as one new data file.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  int|null  $basedOnVersion  the version this writer read before deciding to write (default: latest)
     * @return int the new table version
     */
    public function append(array $rows, ?int $basedOnVersion = null): int
    {
        return $this->commit([$this->writeDataFile($rows)], $basedOnVersion);
    }

    /**
     * Delete the rows matching a predicate.
     *
     * Copy-on-write: every file containing a match is rewritten without the
     * deleted rows. Slow to write, nothing extra to do when reading.
     *
     * Merge-on-read: only a small list of deleted positions is committed.
     * Fast to write; every reader has to filter those positions out.
     *
     * @param  Closure(array): bool  $where
     */
    public function delete(Closure $where, bool $mergeOnRead = false): int
    {
        $state = $this->stateAt($this->version());
        $actions = [];

        foreach ($state['files'] as $path => $file) {
            $rows = $this->readDataFile($path);
            $live = array_diff_key($rows, array_flip($state['deletedPositions'][$path] ?? []));
            $matching = array_keys(array_filter($live, $where));

            if ($matching === []) {
                continue;
            }

            if ($mergeOnRead) {
                $actions[] = ['op' => 'delete-rows', 'path' => $path, 'positions' => $matching];

                continue;
            }

            $kept = array_values(array_diff_key($live, array_flip($matching)));
            $actions[] = ['op' => 'remove', 'path' => $path];

            if ($kept !== []) {
                $actions[] = $this->writeDataFile($kept);
                $this->rowsRewritten += count($kept);
            }
        }

        return $actions === [] ? $this->version() : $this->commit($actions);
    }

    /** Schema evolution: a metadata-only commit. No data file is touched. */
    public function addColumn(string $column, mixed $default = null): int
    {
        return $this->commit([['op' => 'add-column', 'column' => $column, 'default' => $default]]);
    }

    /** Rewrite all live rows into a single file, applying pending merge-on-read deletes. */
    public function compact(): int
    {
        $rows = $this->scan();
        $actions = array_map(
            fn (string $path) => ['op' => 'remove', 'path' => $path],
            array_keys($this->stateAt($this->version())['files']),
        );

        if ($rows !== []) {
            $actions[] = $this->writeDataFile($rows);
            $this->rowsRewritten += count($rows);
        }

        return $this->commit($actions);
    }

    // ---- Reading -----------------------------------------------------------------------

    /**
     * Read the table.
     *
     * @param  int|null  $asOfVersion  time travel: read the table as it was at this version
     * @param  array<string, mixed>  $where  column => value filters, used both to skip files and to filter rows
     * @return list<array<string, mixed>>
     */
    public function scan(?int $asOfVersion = null, array $where = []): array
    {
        $state = $this->stateAt($asOfVersion ?? $this->version());
        $result = [];

        foreach ($state['files'] as $path => $file) {
            if (! $this->mayContain($file['stats'], $where)) {
                $this->filesSkipped++; // the statistics prove no row in this file can match

                continue;
            }

            if (! $this->store->exists($path)) {
                throw new RuntimeException("Version is no longer readable: [{$path}] was removed by vacuum.");
            }

            $this->filesScanned++;
            $deleted = array_flip($state['deletedPositions'][$path] ?? []);

            foreach ($this->readDataFile($path) as $position => $row) {
                if (isset($deleted[$position])) {
                    continue; // merge-on-read: drop rows marked as deleted
                }

                $row += $state['columnDefaults']; // columns added after this file was written

                if ($this->matches($row, $where)) {
                    $result[] = $row;
                }
            }
        }

        return $result;
    }

    /** The latest committed version (0 for a brand-new table). */
    public function version(): int
    {
        return count($this->store->list($this->logPrefix()));
    }

    /** @return list<string> the data files that make up the table at a version */
    public function dataFiles(?int $asOfVersion = null): array
    {
        return array_keys($this->stateAt($asOfVersion ?? $this->version())['files']);
    }

    /**
     * Physically delete data files that no version from $keepFromVersion
     * onwards refers to. Frees storage, and ends time travel to anything older.
     *
     * @return int number of files deleted
     */
    public function vacuum(int $keepFromVersion): int
    {
        $needed = [];

        for ($version = $keepFromVersion; $version <= $this->version(); $version++) {
            $needed += array_flip($this->dataFiles($version));
        }

        $deleted = 0;

        foreach ($this->store->list("{$this->name}/data/") as $path) {
            if (! isset($needed[$path])) {
                $this->store->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    // ---- Internals ---------------------------------------------------------------------

    /**
     * Atomically publish a new version.
     *
     * @param  list<array<string, mixed>>  $actions
     *
     * @throws ConcurrentCommitException
     */
    private function commit(array $actions, ?int $basedOnVersion = null): int
    {
        $newVersion = ($basedOnVersion ?? $this->version()) + 1;

        // The whole transaction hinges on this one conditional write. If two
        // writers race for the same version number, exactly one succeeds.
        $committed = $this->store->putIfAbsent(
            $this->logPrefix().sprintf('%08d.json', $newVersion),
            json_encode($actions, JSON_THROW_ON_ERROR),
        );

        if (! $committed) {
            throw new ConcurrentCommitException("Version {$newVersion} was already committed by another writer.");
        }

        return $newVersion;
    }

    /**
     * Replay the log up to a version.
     *
     * @return array{files: array<string, array{rows: int, stats: array}>, deletedPositions: array<string, list<int>>, columnDefaults: array<string, mixed>}
     */
    private function stateAt(int $version): array
    {
        $state = ['files' => [], 'deletedPositions' => [], 'columnDefaults' => []];

        foreach (array_slice($this->store->list($this->logPrefix()), 0, $version) as $logFile) {
            foreach (json_decode($this->store->get($logFile), true, flags: JSON_THROW_ON_ERROR) as $action) {
                match ($action['op']) {
                    'add' => $state['files'][$action['path']] = ['rows' => $action['rows'], 'stats' => $action['stats']],
                    'remove' => $state = $this->withoutFile($state, $action['path']),
                    'delete-rows' => $state['deletedPositions'][$action['path']] = [
                        ...$state['deletedPositions'][$action['path']] ?? [],
                        ...$action['positions'],
                    ],
                    'add-column' => $state['columnDefaults'][$action['column']] = $action['default'],
                };
            }
        }

        return $state;
    }

    private function withoutFile(array $state, string $path): array
    {
        unset($state['files'][$path], $state['deletedPositions'][$path]);

        return $state;
    }

    /**
     * Write rows to a new immutable data file and describe it for the log.
     * Nothing references the file until the commit succeeds, so a crash
     * here leaves an orphan, never a half-visible write.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{op: string, path: string, rows: int, stats: array<string, array{min: mixed, max: mixed}>}
     */
    private function writeDataFile(array $rows): array
    {
        do {
            $path = sprintf('%s/data/part-%05d.json', $this->name, ++$this->fileSequence);
        } while (! $this->store->putIfAbsent($path, json_encode(array_values($rows), JSON_THROW_ON_ERROR)));

        $stats = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                if (is_scalar($value)) {
                    $stats[$column]['min'] = min($stats[$column]['min'] ?? $value, $value);
                    $stats[$column]['max'] = max($stats[$column]['max'] ?? $value, $value);
                }
            }
        }

        return ['op' => 'add', 'path' => $path, 'rows' => count($rows), 'stats' => $stats];
    }

    /** @return list<array<string, mixed>> */
    private function readDataFile(string $path): array
    {
        return json_decode($this->store->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Can a file with these min/max statistics contain a row matching the filter? */
    private function mayContain(array $stats, array $where): bool
    {
        foreach ($where as $column => $value) {
            if (isset($stats[$column]) && ($value < $stats[$column]['min'] || $value > $stats[$column]['max'])) {
                return false;
            }
        }

        return true;
    }

    private function matches(array $row, array $where): bool
    {
        foreach ($where as $column => $value) {
            if (($row[$column] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function logPrefix(): string
    {
        return "{$this->name}/_log/";
    }
}
