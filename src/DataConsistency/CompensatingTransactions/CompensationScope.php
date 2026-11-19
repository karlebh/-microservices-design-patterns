<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\CompensatingTransactions;

use Closure;
use Throwable;

/**
 * A unit of work whose steps each commit immediately and are undone, if
 * needed, by running compensating actions.
 *
 *   $scope = new CompensationScope;
 *   try {
 *       $flight = $scope->run('flight', fn () => book(), fn ($ref) => cancel($ref));
 *       $hotel  = $scope->run('hotel',  fn () => book(), fn ($ref) => cancel($ref));
 *   } catch (Throwable $e) {
 *       $scope->compensate();
 *   }
 */
final class CompensationScope
{
    /** @var list<array{name: string, undo: Closure}> completed steps, oldest first */
    private array $undoStack = [];

    /** @var list<string> what happened, in order */
    public array $journal = [];

    /** @var list<string> steps whose compensation kept failing: a human has to fix these */
    public array $needsManualIntervention = [];

    public function __construct(private readonly int $maxCompensationAttempts = 3) {}

    /**
     * Execute a step. If it succeeds, remember how to undo it.
     *
     * @param  Closure(): mixed  $do
     * @param  Closure(mixed): void  $undo  receives whatever $do returned (a booking ref, a charge id...)
     */
    public function run(string $name, Closure $do, Closure $undo): mixed
    {
        $result = $do(); // if this throws, the step did nothing and needs no undo

        $this->undoStack[] = ['name' => $name, 'undo' => fn () => $undo($result)];
        $this->journal[] = "done: {$name}";

        return $result;
    }

    /**
     * Undo every completed step, newest first.
     *
     * Never throws. A compensation that keeps failing is recorded for manual
     * intervention and the remaining steps are still compensated: giving up
     * half-way would leave more inconsistency, not less.
     */
    public function compensate(): void
    {
        while ($step = array_pop($this->undoStack)) {
            $this->compensateStep($step['name'], $step['undo']);
        }
    }

    private function compensateStep(string $name, Closure $undo): void
    {
        for ($attempt = 1; $attempt <= $this->maxCompensationAttempts; $attempt++) {
            try {
                $undo();
                $this->journal[] = "compensated: {$name}";

                return;
            } catch (Throwable) {
                $this->journal[] = "compensation attempt {$attempt} failed: {$name}";
            }
        }

        $this->needsManualIntervention[] = $name;
    }
}
