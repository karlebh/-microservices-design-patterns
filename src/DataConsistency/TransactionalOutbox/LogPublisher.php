<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox;

use Illuminate\Support\Facades\Log;
use Patterns\DataConsistency\TransactionalOutbox\Models\OutboxMessage;

/** Development stand-in for a broker: "publishing" writes a log line. */
final class LogPublisher implements MessagePublisher
{
    public function publish(OutboxMessage $message): void
    {
        Log::info('outbox.published', [
            'message_id' => $message->message_id,
            'type' => $message->type,
            'aggregate' => $message->aggregate_type.':'.$message->aggregate_id,
            'payload' => $message->payload,
        ]);
    }
}
