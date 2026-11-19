<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\WriteAheadLog;

use RuntimeException;

/**
 * An append-only log file.
 *
 * On-disk format, one record after another:
 *
 *   +----------------+----------------+---------------------------+
 *   | length (4 B)   | CRC32 (4 B)    | payload (length bytes)    |
 *   +----------------+----------------+---------------------------+
 *
 * The payload is JSON and includes the record's LSN. The length says where
 * the record ends; the checksum says whether what is there can be trusted.
 * Together they let recovery tell a complete record from one that was cut
 * off by a crash or damaged on disk.
 */
final class WriteAheadLog
{
    private const HEADER_BYTES = 8;

    /** @var resource */
    private $handle;

    private int $nextLsn;

    /**
     * @param  int  $firstLsn  the LSN to continue from when the file is empty (e.g. right after a checkpoint)
     */
    public function __construct(private readonly string $path, int $firstLsn = 1)
    {
        ['records' => $records, 'validBytes' => $validBytes] = $this->scan();

        // A crash can leave an incomplete record at the end. Cut it off now:
        // appending after garbage would hide every later record from replay.
        if (is_file($path) && filesize($path) > $validBytes) {
            $file = fopen($path, 'r+b');
            ftruncate($file, $validBytes);
            fclose($file);
        }

        $this->nextLsn = max($firstLsn, $records === [] ? 0 : array_key_last($records) + 1);
        $this->handle = fopen($path, 'ab') ?: throw new RuntimeException("Cannot open log file [{$path}].");
    }

    /**
     * Append one record and force it to disk.
     *
     * When this returns, the record survives a power failure.
     *
     * @return int the record's log sequence number
     */
    public function append(array $record): int
    {
        $lsn = $this->nextLsn++;
        $payload = json_encode(['lsn' => $lsn] + $record, JSON_THROW_ON_ERROR);

        fwrite($this->handle, pack('NN', strlen($payload), crc32($payload)).$payload);

        // fflush() hands the bytes to the operating system; fsync() makes the
        // OS put them on the physical disk. Without fsync, "written" only
        // means "in the OS page cache".
        fflush($this->handle);
        fsync($this->handle);

        return $lsn;
    }

    /**
     * Every intact record after the given LSN, in order.
     *
     * @return array<int, array> lsn => record
     */
    public function replay(int $afterLsn = 0): array
    {
        return array_filter(
            $this->scan()['records'],
            fn (int $lsn) => $lsn > $afterLsn,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** The LSN of the most recently appended record (0 if nothing was ever logged). */
    public function lastLsn(): int
    {
        return $this->nextLsn - 1;
    }

    /** Discard the log's contents. Only safe once they are captured in a checkpoint. */
    public function truncate(): void
    {
        fclose($this->handle);
        $this->handle = fopen($this->path, 'wb'); // 'w' empties the file

        fflush($this->handle);
        fsync($this->handle);
    }

    public function sizeBytes(): int
    {
        clearstatcache(true, $this->path);

        return (int) filesize($this->path);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Read the file from the start and stop at the first record that is
     * incomplete or fails its checksum. Nothing after that point is used.
     *
     * @return array{records: array<int, array>, validBytes: int}
     */
    private function scan(): array
    {
        $bytes = is_file($this->path) ? (string) file_get_contents($this->path) : '';
        $records = [];
        $offset = 0;

        while ($offset + self::HEADER_BYTES <= strlen($bytes)) {
            $header = unpack('Nlength/Nchecksum', $bytes, $offset);
            $payload = substr($bytes, $offset + self::HEADER_BYTES, $header['length']);

            $complete = strlen($payload) === $header['length'];
            $intact = $complete && crc32($payload) === $header['checksum'];

            if (! $intact) {
                break; // a torn write or corruption
            }

            $record = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $records[$record['lsn']] = $record;

            $offset += self::HEADER_BYTES + $header['length'];
        }

        return ['records' => $records, 'validBytes' => $offset];
    }
}
