<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Patterns\Resilience\CircuitBreaker\CircuitBreaker;
use Patterns\Resilience\CircuitBreaker\CircuitOpenException;
use Patterns\Resilience\CircuitBreaker\CircuitState;
use Patterns\Resilience\CircuitBreaker\PaymentService;
use RuntimeException;
use Tests\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker('inventory', Cache::store(), failureThreshold: 3, openSeconds: 30);
    }

    private function causeFailures(CircuitBreaker $breaker, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            try {
                $breaker->call(fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException) {
                // expected
            }
        }
    }

    public function test_it_stays_closed_while_failures_are_below_the_threshold(): void
    {
        $breaker = $this->breaker();

        $this->causeFailures($breaker, 2);

        $this->assertSame(CircuitState::Closed, $breaker->state());
        $this->assertSame('ok', $breaker->call(fn () => 'ok'));
    }

    public function test_it_opens_at_the_threshold_and_then_fails_fast_without_calling_the_dependency(): void
    {
        $breaker = $this->breaker();
        $this->causeFailures($breaker, 3);

        $this->assertSame(CircuitState::Open, $breaker->state());

        $called = false;

        try {
            $breaker->call(function () use (&$called) {
                $called = true;
            });
            $this->fail('The call should have been rejected.');
        } catch (CircuitOpenException) {
            // expected
        }

        $this->assertFalse($called, 'The dependency was not touched.');
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $breaker = $this->breaker();

        $this->causeFailures($breaker, 2);
        $breaker->call(fn () => 'ok');
        $this->causeFailures($breaker, 2);

        $this->assertSame(CircuitState::Closed, $breaker->state(), 'Only CONSECUTIVE failures trip the breaker.');
    }

    public function test_after_the_cooldown_a_successful_probe_closes_the_circuit(): void
    {
        $breaker = $this->breaker();
        $this->causeFailures($breaker, 3);

        $this->travel(31)->seconds();
        $this->assertSame(CircuitState::HalfOpen, $breaker->state());

        $this->assertSame('recovered', $breaker->call(fn () => 'recovered'));
        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    public function test_a_failed_probe_reopens_the_circuit_for_a_full_cooldown(): void
    {
        $breaker = $this->breaker();
        $this->causeFailures($breaker, 3);

        $this->travel(31)->seconds();
        $this->causeFailures($breaker, 1); // the single probe fails

        $this->assertSame(CircuitState::Open, $breaker->state(), 'One failure is enough when half-open.');

        $this->travel(29)->seconds();
        $this->assertSame(CircuitState::Open, $breaker->state(), 'The cooldown restarted from the failed probe.');
    }

    public function test_only_one_probe_is_let_through_while_half_open(): void
    {
        $breaker = $this->breaker();
        $this->causeFailures($breaker, 3);
        $this->travel(31)->seconds();

        $secondCaller = null;

        // While the first probe is still in flight, a second caller arrives.
        $breaker->call(function () use ($breaker, &$secondCaller) {
            try {
                $breaker->call(fn () => 'second probe');
            } catch (CircuitOpenException $e) {
                $secondCaller = $e;
            }

            return 'first probe';
        });

        $this->assertInstanceOf(CircuitOpenException::class, $secondCaller);
    }

    public function test_a_fallback_gives_a_degraded_answer_instead_of_an_error(): void
    {
        $breaker = $this->breaker();
        $this->causeFailures($breaker, 3);

        $stock = $breaker->call(
            fn () => throw new RuntimeException('should not run'),
            fallback: fn () => ['in_stock' => null, 'note' => 'stock level unavailable'],
        );

        $this->assertSame('stock level unavailable', $stock['note']);
    }

    public function test_the_payment_service_stops_calling_the_api_once_the_circuit_is_open(): void
    {
        Http::fake(['payment-api.com/*' => Http::response(null, 500)]);
        $payments = new PaymentService;

        for ($i = 0; $i < 5; $i++) {
            try {
                $payments->charge(['amount' => 100]);
            } catch (Exception) {
                // the API is failing
            }
        }

        Http::assertSentCount(5);

        // Sixth call: rejected locally, the API never sees it.
        try {
            $payments->charge(['amount' => 100]);
            $this->fail('Expected the circuit to be open.');
        } catch (CircuitOpenException) {
            // expected
        }

        Http::assertSentCount(5);
    }

    public function test_the_payment_service_returns_the_api_response_when_healthy(): void
    {
        Http::fake(['payment-api.com/*' => Http::response(['id' => 'ch_1', 'status' => 'succeeded'])]);

        $this->assertSame('succeeded', (new PaymentService)->charge(['amount' => 100])['status']);
    }
}
