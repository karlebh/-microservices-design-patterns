<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DeliverySemantics;

enum DeliveryGuarantee
{
    /** Acknowledge first, process second. May lose messages, never duplicates. */
    case AtMostOnce;

    /** Process first, acknowledge second. Never loses messages, may duplicate. */
    case AtLeastOnce;

    /** Process and acknowledge in one atomic step. Neither loss nor duplicates. */
    case ExactlyOnce;
}
