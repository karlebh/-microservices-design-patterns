<?php

declare(strict_types=1);

namespace Patterns\Resilience\ChaosEngineering;

final readonly class ExperimentReport
{
    public function __construct(
        public string $name,
        /** `completed`, or `not started` when the system was unhealthy to begin with. */
        public string $status,
        /** The steady-state metric before, during and after the fault. */
        public ?float $baseline,
        public ?float $duringFault,
        public ?float $afterRollback,
        /** Did the steady state hold while the fault was active? False = a weakness was found. */
        public bool $hypothesisHeld,
        /** Did the system return to steady state once the fault was removed? */
        public bool $recovered,
    ) {}
}
