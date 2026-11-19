<?php

declare(strict_types=1);

namespace Patterns\Resilience\ChaosEngineering;

use Closure;

/**
 * One chaos experiment, run the way an experiment should be:
 *
 *   1. measure the steady state (baseline)      - refuse to continue if it is already bad
 *   2. inject the fault
 *   3. measure the steady state again           - this tests the hypothesis
 *   4. remove the fault, no matter what happened
 *   5. measure once more                        - did the system recover by itself?
 *
 * The hypothesis is always the same sentence: "the steady-state metric
 * stays at or above the threshold while the fault is active".
 */
final class ChaosExperiment
{
    /**
     * @param  Closure(): float  $steadyState  measures the user-visible health signal (e.g. success rate, 0.0 - 1.0)
     * @param  float  $threshold  the metric must stay at or above this
     * @param  Closure(): void  $injectFault  starts the failure
     * @param  Closure(): void  $rollback  stops it
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $steadyState,
        private readonly float $threshold,
        private readonly Closure $injectFault,
        private readonly Closure $rollback,
    ) {}

    public function run(): ExperimentReport
    {
        $baseline = ($this->steadyState)();

        // Chaos on a system that is already struggling teaches nothing and hurts users.
        if ($baseline < $this->threshold) {
            return new ExperimentReport($this->name, 'not started', $baseline, null, null, hypothesisHeld: false, recovered: false);
        }

        ($this->injectFault)();

        try {
            $duringFault = ($this->steadyState)();
        } finally {
            ($this->rollback)(); // the fault must never outlive the experiment
        }

        $afterRollback = ($this->steadyState)();

        return new ExperimentReport(
            $this->name,
            'completed',
            $baseline,
            $duringFault,
            $afterRollback,
            hypothesisHeld: $duringFault >= $this->threshold,
            recovered: $afterRollback >= $this->threshold,
        );
    }
}
