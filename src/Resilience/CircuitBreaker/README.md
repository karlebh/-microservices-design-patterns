# Circuit Breaker

## The problem

A dependency (payment API, another service) is down or slow. Every request to it now waits for a timeout and then fails. Threads pile up waiting, your own service slows down, and its callers start timing out too: one failure cascades through the system. Meanwhile the struggling dependency is hammered with traffic it can't serve, which keeps it from recovering.

## The idea

Wrap the call in an object that watches for failures and, past a threshold, **stops calling** for a while. Like an electrical breaker, it has states:

```
          failures >= threshold
 CLOSED ───────────────────────► OPEN
   ▲                               │ cooldown elapsed
   │ probe succeeds                ▼
   └────────────────────────── HALF-OPEN ──► probe fails ──► OPEN
```

- **Closed** — calls pass through; consecutive failures are counted.
- **Open** — calls fail immediately, without touching the dependency.
- **Half-open** — after the cooldown, a *single* trial call is let through. Success closes the circuit, failure opens it again.

## In this folder

| File | Role |
| --- | --- |
| `CircuitBreaker.php` | The breaker. State lives in the cache so every PHP worker shares it |
| `CircuitState.php` | `Closed`, `Open`, `HalfOpen` |
| `CircuitOpenException.php` | Thrown when a call is rejected without being attempted |
| `PaymentService.php` | Example client: an HTTP payment call protected by the breaker |

## What to notice

- **Why the cache?** PHP-FPM shares nothing between requests. A breaker held in a PHP object would start closed on every request and never trip. Shared storage (Redis/database cache) makes all workers see the same circuit.
- **Fail fast is the feature.** An instant error (or a fallback) is better than a 3-second timeout multiplied by every request.
- **Only one probe.** Half-open uses an atomic `add()` so a recovering service gets one trial request, not a stampede.
- Tune the threshold and cooldown per dependency, and decide what counts as a failure (timeouts and 5xx yes; a 404 or validation error usually no).
- Pairs naturally with [retries](../RetryWithBackoff) (retry *inside* the breaker), [fallbacks](../GracefulDegradation) and [bulkheads](../Bulkhead).

## Try it

```bash
php artisan test --filter=CircuitBreakerTest
```
