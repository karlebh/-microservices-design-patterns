<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox;

use Illuminate\Support\Facades\DB;
use Patterns\DataConsistency\TransactionalOutbox\Models\OutboxMessage;
use Throwable;

/**
 * The "message relay": moves committed outbox rows to the broker.
 *
 * Guarantees:
 *  - every committed message is published AT LEAST once
 *  - messages are published in the order they were written
 *
 * It cannot guarantee exactly-once: "publish" and "mark as published" are
 * again two systems, so a crash between them means a re-publish on restart.
 * The fix lives on the consumer side (dedupe on message_id).
 */
final class OutboxRelay
{
    public function __construct(private readonly MessagePublisher $publisher) {}

    /** @return int how many messages were published in this run */
    public function relay(int $batchSize = 100): int
    {
        return DB::transaction(function () use ($batchSize) {
            $pending = OutboxMessage::query()
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit($batchSize);

            // With several relay processes, each must pick different rows.
            // SKIP LOCKED makes a second relay jump over rows the first one holds.
            if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
                $pending->lock('for update skip locked');
            }

            $published = 0;

            foreach ($pending->get() as $message) {
                try {
                    $this->publisher->publish($message);
                } catch (Throwable $e) {
                    $message->update([
                        'attempts' => $message->attempts + 1,
                        'last_error' => $e->getMessage(),
                    ]);

                    // Stop here: publishing the next message would break ordering.
                    break;
                }

                $message->update([
                    'attempts' => $message->attempts + 1,
                    'last_error' => null,
                    'published_at' => now(),
                ]);

                $published++;
            }

            return $published;
        });
    }
}
