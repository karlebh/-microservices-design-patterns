<?php

declare(strict_types=1);

namespace App\Services\CircuitBreaker;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Circuit Breaker Implementation
 *
 * This service implements a simple circuit breaker pattern to prevent
 * repeated calls to a failing external dependency (e.g. payment API).
 *
 * Behaviour:
 * - Tracks consecutive failures using a cache store
 * - Opens the circuit after a defined threshold of failures
 * - While the circuit is open, requests fail fast without calling the external service
 * - Automatically allows retries after a cooldown period
 *
 * States:
 * - CLOSED: Normal operation, requests are allowed
 * - OPEN: External service is considered unhealthy, requests are blocked
 * - (Optional extension) HALF-OPEN: Used to test recovery before fully closing circuit
 *
 * Purpose:
 * - Prevent cascading failures
 * - Reduce latency caused by timeouts
 * - Protect application and external service stability
 * - Improve system resilience under partial outages
 */

class PaymentService
{
	private string $failureKey = 'payment_service_failures';

	private string $circuitKey = 'payment_service_circuit_open';

	public function charge(array $payload): array
	{
		// Circuit is OPEN
		if (Cache::get($this->circuitKey)) {
			throw new Exception('Payment service temporarily unavailable.');
		}

		try {
			$response = Http::timeout(3)
				->post('https://payment-api.com/charge', $payload);

			if (! $response->successful()) {
				$this->recordFailure();

				throw new Exception('Payment request failed.');
			}

			Cache::forget($this->failureKey);

			return $response->json();
		} catch (Exception $e) {

			$this->recordFailure();

			throw $e;
		}
	}

	private function recordFailure(): void
	{
		$failures = Cache::increment($this->failureKey);

		// Open circuit after 5 failures
		if ($failures >= 5) {

			// Open circuit for 30 seconds
			Cache::put(
				$this->circuitKey,
				true,
				now()->addSeconds(30)
			);
		}
	}
}
