<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox;

use Patterns\DataConsistency\TransactionalOutbox\Models\OutboxMessage;

/**
 * Port to the message broker (Kafka, RabbitMQ, SNS...).
 *
 * Contract: return normally only when the broker has acknowledged the
 * message. Throw on anything else — the relay will retry.
 */
interface MessagePublisher
{
    public function publish(OutboxMessage $message): void;
}
