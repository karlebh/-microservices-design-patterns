<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\PublishSubscribe;

enum EventStyle
{
    /** Thin event: says WHAT changed and for whom. Consumers fetch the details themselves. */
    case Notification;

    /** Fat event: carries the new state, so consumers never need to ask. */
    case StateTransfer;
}
