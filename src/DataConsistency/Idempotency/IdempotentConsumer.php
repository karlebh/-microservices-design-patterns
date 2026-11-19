<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Idempotency;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a handler at most once per (consumer, message id).
 *
 * The "have I seen this?" marker and the handler's own writes are committed
 * in ONE transaction:
 *
 *   - handler fails  -> marker rolls back too -> the redelivery is processed
 *   - handler works  -> marker commits        -> the redelivery is skipped
 *
 * So an at-least-once delivery behaves like an exactly-once *effect*.
 */
final class IdempotentConsumer
{
    /**
     * @return bool true if the handler ran, false if this message was a duplicate
     */
    public function process(string $consumer, string $messageId, Closure $handler): bool
    {
        return DB::transaction(function () use ($consumer, $messageId, $handler) {
            // INSERT ... ON CONFLICT DO NOTHING. The unique index on
            // (consumer, message_id) decides atomically who gets to proceed,
            // even when two workers receive the same message at once.
            $claimed = DB::table('processed_messages')->insertOrIgnore([
                'consumer' => $consumer,
                'message_id' => $messageId,
                'processed_at' => now(),
            ]) === 1;

            if (! $claimed) {
                return false;
            }

            $handler();

            return true;
        });
    }
}
