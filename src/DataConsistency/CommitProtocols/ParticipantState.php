<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\CommitProtocols;

enum ParticipantState: string
{
    case Init = 'init';

    /** Voted yes. Work is durable, locks are held, and it can no longer decide alone. */
    case Prepared = 'prepared';

    /** 3PC only: has been told that EVERY participant voted yes. */
    case PreCommitted = 'pre_committed';

    case Committed = 'committed';

    case Aborted = 'aborted';
}
