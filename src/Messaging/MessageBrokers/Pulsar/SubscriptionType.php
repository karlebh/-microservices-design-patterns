<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageBrokers\Pulsar;

/**
 * In Pulsar the SUBSCRIPTION, not the topic, decides whether you get
 * streaming or queuing behaviour.
 */
enum SubscriptionType
{
    /** One consumer only. A second one is refused. Strict order. */
    case Exclusive;

    /** Several consumers connect, one is active; a standby takes over if it disconnects. Strict order. */
    case Failover;

    /** Consumers compete; each message goes to one of them. A work queue. No ordering. */
    case Shared;

    /** Like Shared, but all messages with the same key go to the same consumer. Order per key. */
    case KeyShared;
}
