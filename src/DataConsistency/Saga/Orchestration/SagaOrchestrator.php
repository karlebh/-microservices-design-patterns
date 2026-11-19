<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Saga\Orchestration;

use Closure;
use Throwable;

/**
 * Orchestrated saga: one object knows the whole workflow and tells each
 * participant what to do next.
 *
 *   step 1 ✔ ─► step 2 ✔ ─► step 3 ✘
 *                              │
 *   undo 1 ◄── undo 2 ◄────────┘      (newest first)
 *
 * Each step is a local transaction in some service. There is no global
 * rollback: steps that already committed are undone by running their
 * compensations, in reverse order.
 */
final class SagaOrchestrator
{
    /** @var list<SagaStep> */
    private array $steps = [];

    public function step(string $name, Closure $action, ?Closure $compensation = null): self
    {
        $this->steps[] = new SagaStep($name, $action, $compensation);

        return $this;
    }

    public function run(array $context = []): SagaResult
    {
        $log = [];
        $completed = [];

        foreach ($this->steps as $step) {
            try {
                $context = array_merge($context, ($step->action)($context) ?? []);
            } catch (Throwable $e) {
                $log[] = "{$step->name}: failed";

                // The failed step committed nothing, so only the earlier ones are undone.
                foreach (array_reverse($completed) as $done) {
                    if ($done->compensation !== null) {
                        ($done->compensation)($context);
                        $log[] = "{$done->name}: compensated";
                    }
                }

                return new SagaResult(false, $context, $log, $step->name, $e->getMessage());
            }

            $completed[] = $step;
            $log[] = "{$step->name}: done";
        }

        return new SagaResult(true, $context, $log);
    }
}
