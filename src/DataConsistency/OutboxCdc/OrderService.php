<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\OutboxCdc;

use Patterns\DataArchitecture\ChangeDataCapture\Database;
use Patterns\DataArchitecture\ChangeDataCapture\Transaction;

/**
 * The application side. It knows nothing about brokers or connectors:
 * it writes two rows in one local transaction and that is all.
 */
final class OrderService
{
    private int $eventSequence = 0;

    public function __construct(private readonly Database $database) {}

    public function placeOrder(string $orderId, string $customer, int $totalCents): void
    {
        $this->database->transaction(function (Transaction $tx) use ($orderId, $customer, $totalCents) {
            $tx->insert('orders', $orderId, ['customer' => $customer, 'total_cents' => $totalCents, 'status' => 'placed']);

            $this->writeToOutbox($tx, 'order', $orderId, 'order.placed', [
                'order_id' => $orderId,
                'customer' => $customer,
                'total_cents' => $totalCents,
            ]);
        });
    }

    public function cancelOrder(string $orderId): void
    {
        $this->database->transaction(function (Transaction $tx) use ($orderId) {
            $tx->update('orders', $orderId, ['status' => 'cancelled']);

            $this->writeToOutbox($tx, 'order', $orderId, 'order.cancelled', ['order_id' => $orderId]);
        });
    }

    private function writeToOutbox(Transaction $tx, string $aggregateType, string $aggregateId, string $type, array $payload): void
    {
        $eventId = 'evt-'.(++$this->eventSequence);

        $tx->insert('outbox', $eventId, [
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'type' => $type,
            'payload' => $payload,
        ]);

        // The insert is now part of this transaction's log records, which is
        // all the connector reads. The row itself is not needed any more, so
        // remove it and the outbox table never grows.
        $tx->delete('outbox', $eventId);
    }
}
