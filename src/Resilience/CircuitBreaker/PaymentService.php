<?php

declare(strict_types=1);

namespace Patterns\Resilience\CircuitBreaker;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Example client of the circuit breaker: charging through an external
 * payment API that may be slow or down.
 *
 * All the failure bookkeeping lives in CircuitBreaker; this class only says
 * what the call is and what counts as a failure.
 */
class PaymentService
{
    private CircuitBreaker $breaker;

    public function __construct(?CircuitBreaker $breaker = null)
    {
        // Open circuit after 5 failures, keep it open for 30 seconds.
        $this->breaker = $breaker ?? new CircuitBreaker(
            service: 'payment_service',
            cache: Cache::store(),
            failureThreshold: 5,
            openSeconds: 30,
        );
    }

    /**
     * @throws CircuitOpenException when the circuit is open (the API was not called)
     * @throws Exception when the payment request itself failed
     */
    public function charge(array $payload): array
    {
        return $this->breaker->call(function () use ($payload) {
            $response = Http::timeout(3)
                ->post('https://payment-api.com/charge', $payload);

            if (! $response->successful()) {
                throw new Exception('Payment request failed.');
            }

            return $response->json();
        });
    }
}
