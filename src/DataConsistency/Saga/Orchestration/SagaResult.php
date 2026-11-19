<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Saga\Orchestration;

final readonly class SagaResult
{
    /**
     * @param  bool  $completed  true: every step committed. false: a step failed and the others were compensated
     * @param  array  $context  data accumulated by the steps (ids, references...)
     * @param  list<string>  $log  what happened, in order
     */
    public function __construct(
        public bool $completed,
        public array $context,
        public array $log,
        public ?string $failedStep = null,
        public ?string $error = null,
    ) {}
}
