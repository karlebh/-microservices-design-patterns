<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\ChangeDataCapture;

/** One row-level change taken from the transaction log (shaped like a Debezium event). */
final readonly class ChangeEvent
{
    public const CREATE = 'c';

    public const UPDATE = 'u';

    public const DELETE = 'd';

    /** A row that already existed, read during the initial snapshot. */
    public const READ = 'r';

    public function __construct(
        /** Log sequence number: position in the log, strictly increasing. */
        public int $lsn,
        /** Changes committed together share a transaction id. */
        public int $transactionId,
        public string $table,
        public string $op,
        public string|int $key,
        /** The row as it was (null for inserts and snapshot reads). */
        public ?array $before,
        /** The row as it is now (null for deletes). */
        public ?array $after,
    ) {}
}
