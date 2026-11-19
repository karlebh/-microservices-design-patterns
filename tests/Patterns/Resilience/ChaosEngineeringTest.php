<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Patterns\Resilience\ChaosEngineering\ChaosExperiment;
use Patterns\Resilience\ChaosEngineering\FaultInjector;
use Patterns\Resilience\ChaosEngineering\InjectedTimeout;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

final class ChaosEngineeringTest extends TestCase
{
    private function injector(): FaultInjector
    {
        return new FaultInjector(new Randomizer(new Mt19937(7)));
    }

    /** Fraction of 200 calls that succeed. */
    private function successRate(callable $operation): float
    {
        $succeeded = 0;

        for ($i = 0; $i < 200; $i++) {
            try {
                $operation();
                $succeeded++;
            } catch (Throwable) {
                // counted as a failure
            }
        }

        return $succeeded / 200;
    }

    // ---- Fault injection ----------------------------------------------------------------

    public function test_without_a_fault_the_injector_is_a_transparent_pass_through(): void
    {
        $injector = $this->injector();

        $this->assertSame('real answer', $injector->call('inventory', fn () => 'real answer'));
        $this->assertSame([], $injector->injected);
    }

    public function test_an_injected_error_makes_the_dependency_fail(): void
    {
        $injector = $this->injector();
        $injector->injectErrors('inventory', message: 'connection refused');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[inventory] connection refused');

        $injector->call('inventory', fn () => 'real answer');
    }

    public function test_a_fault_only_affects_its_target(): void
    {
        $injector = $this->injector();
        $injector->injectErrors('inventory');

        $this->assertSame('ok', $injector->call('pricing', fn () => 'ok'));
    }

    public function test_the_blast_radius_can_be_limited_to_a_share_of_requests(): void
    {
        $injector = $this->injector();
        $injector->injectErrors('inventory', probability: 0.1);

        $rate = $this->successRate(fn () => $injector->call('inventory', fn () => 'ok'));

        $this->assertEqualsWithDelta(0.9, $rate, 0.06, 'About one request in ten is affected; the rest are untouched.');
    }

    public function test_injected_latency_only_hurts_callers_without_enough_patience(): void
    {
        $injector = $this->injector();
        $injector->injectLatency('inventory', latencyMs: 800);

        $this->assertSame('ok', $injector->call('inventory', fn () => 'ok', timeoutMs: 2_000), 'Slow, but within this caller\'s timeout.');

        $this->expectException(InjectedTimeout::class);
        $injector->call('inventory', fn () => 'ok', timeoutMs: 300);
    }

    public function test_the_kill_switch_stops_every_fault_at_once(): void
    {
        $injector = $this->injector();
        $injector->injectErrors('inventory');
        $injector->injectLatency('pricing', 5_000);

        $injector->enabled = false;

        $this->assertSame('ok', $injector->call('inventory', fn () => 'ok'));
        $this->assertSame('ok', $injector->call('pricing', fn () => 'ok', timeoutMs: 100));
    }

    // ---- Experiments --------------------------------------------------------------------

    /** A product page that needs recommendations and has no plan B. */
    private function fragilePage(FaultInjector $injector): callable
    {
        return fn () => ['title' => 'Keyboard', 'related' => $injector->call('recommendations', fn () => ['mouse'])];
    }

    /** The same page, but recommendations are treated as optional. */
    private function resilientPage(FaultInjector $injector): callable
    {
        return function () use ($injector) {
            try {
                $related = $injector->call('recommendations', fn () => ['mouse']);
            } catch (Throwable) {
                $related = []; // fallback: render the page without the carousel
            }

            return ['title' => 'Keyboard', 'related' => $related];
        };
    }

    private function experiment(FaultInjector $injector, callable $page): ChaosExperiment
    {
        return new ChaosExperiment(
            name: 'recommendation service outage',
            steadyState: fn () => $this->successRate($page),
            threshold: 0.99,
            injectFault: fn () => $injector->injectErrors('recommendations'),
            rollback: fn () => $injector->clear('recommendations'),
        );
    }

    public function test_an_experiment_confirms_that_a_fallback_really_works(): void
    {
        $injector = $this->injector();

        $report = $this->experiment($injector, $this->resilientPage($injector))->run();

        $this->assertTrue($report->hypothesisHeld, 'Pages kept rendering while recommendations were down.');
        $this->assertSame(1.0, $report->duringFault);
        $this->assertSame(200, $injector->injected['recommendations'], 'The fault really was active: every call was sabotaged.');
    }

    public function test_an_experiment_exposes_a_hidden_single_point_of_failure(): void
    {
        $injector = $this->injector();

        $report = $this->experiment($injector, $this->fragilePage($injector))->run();

        $this->assertSame(1.0, $report->baseline, 'Everything looked fine before...');
        $this->assertSame(0.0, $report->duringFault, '...but an optional feature takes the whole page down.');
        $this->assertFalse($report->hypothesisHeld, 'Hypothesis refuted: this is the finding the experiment exists for.');
        $this->assertTrue($report->recovered, 'At least the system heals once the dependency is back.');
    }

    public function test_the_fault_is_always_rolled_back(): void
    {
        $injector = $this->injector();

        $this->experiment($injector, $this->fragilePage($injector))->run();
        $this->assertFalse($injector->hasFaults());

        // Even when measuring blows up half way through.
        $measurements = 0;
        $exploding = new ChaosExperiment(
            name: 'measurement failure',
            steadyState: function () use (&$measurements) {
                if (++$measurements === 2) {
                    throw new RuntimeException('monitoring is unreachable');
                }

                return 1.0;
            },
            threshold: 0.99,
            injectFault: fn () => $injector->injectErrors('recommendations'),
            rollback: fn () => $injector->clear(),
        );

        try {
            $exploding->run();
        } catch (RuntimeException) {
            // the experiment itself failed
        }

        $this->assertFalse($injector->hasFaults(), 'A fault left behind would turn an experiment into an incident.');
    }

    public function test_an_experiment_refuses_to_start_on_an_unhealthy_system(): void
    {
        $injector = $this->injector();
        $faultInjected = false;

        $report = (new ChaosExperiment(
            name: 'outage on top of an outage',
            steadyState: fn () => 0.80, // already below the 99% bar
            threshold: 0.99,
            injectFault: function () use (&$faultInjected) {
                $faultInjected = true;
            },
            rollback: fn () => null,
        ))->run();

        $this->assertSame('not started', $report->status);
        $this->assertFalse($faultInjected, 'Do not add failures to a system that is already failing.');
    }
}
