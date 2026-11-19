<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Saga\Orchestration;

use Closure;

/** One local transaction of a saga, together with the way to undo it. */
final readonly class SagaStep
{
    /**
     * @param  Closure(array): ?array  $action  receives the saga context; may return values to add to it
     * @param  (Closure(array): void)|null  $compensation  semantically undoes the action; null if nothing to undo
     */
    public function __construct(
        public string $name,
        public Closure $action,
        public ?Closure $compensation = null,
    ) {}
}
