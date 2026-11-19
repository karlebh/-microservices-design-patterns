<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\EventSourcing;

/**
 * Something that happened, named in the past tense. Immutable by design:
 * history is corrected by adding new events, never by editing old ones.
 */
final readonly class DomainEvent
{
    public const ACCOUNT_OPENED = 'AccountOpened';

    public const MONEY_DEPOSITED = 'MoneyDeposited';

    public const MONEY_WITHDRAWN = 'MoneyWithdrawn';

    public function __construct(
        public string $type,
        public array $payload = [],
    ) {}
}
