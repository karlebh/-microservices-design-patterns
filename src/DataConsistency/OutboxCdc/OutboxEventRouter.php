<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\OutboxCdc;

use Closure;
use Patterns\DataArchitecture\ChangeDataCapture\ChangeEvent;

/**
 * Turns raw change events on the `outbox` table into proper messages.
 * Plug it into a CdcConnector as a sink.
 *
 *   outbox row                       message
 *   ----------                       -------
 *   primary key        ───────────►  id   (stable: consumers dedupe on it)
 *   aggregate_type     ───────────►  topic  ("order" -> "order.events")
 *   aggregate_id       ───────────►  key    (same order -> same partition -> ordered)
 *   type, payload      ───────────►  type, payload
 */
final class OutboxEventRouter
{
    /** @param Closure(string $topic, array $message): void $publish hands the message to the broker */
    public function __construct(
        private readonly Closure $publish,
        private readonly string $outboxTable = 'outbox',
    ) {}

    public function __invoke(ChangeEvent $change): void
    {
        // Only inserts are messages. The delete that follows each insert,
        // and every other table, is noise for this router.
        if ($change->table !== $this->outboxTable || $change->op !== ChangeEvent::CREATE) {
            return;
        }

        $row = $change->after;

        ($this->publish)($row['aggregate_type'].'.events', [
            'id' => $change->key,
            'key' => $row['aggregate_id'],
            'type' => $row['type'],
            'payload' => $row['payload'],
        ]);
    }
}
