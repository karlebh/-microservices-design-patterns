<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox\Console;

use Illuminate\Console\Command;
use Patterns\DataConsistency\TransactionalOutbox\OutboxRelay;

/**
 * The relay as a process. In production this runs continuously
 * (supervisor / a scheduled task every few seconds).
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay {--batch=100 : Maximum messages to publish in this run}';

    protected $description = 'Publish pending transactional-outbox messages to the broker';

    public function handle(OutboxRelay $relay): int
    {
        $published = $relay->relay((int) $this->option('batch'));

        $this->info("Published {$published} outbox message(s).");

        return self::SUCCESS;
    }
}
