# Exponential Backoff with Jitter Retries

## The problem

Many failures are transient: a dropped packet, a brief overload, a leader election. Trying again usually works. But *how* you retry decides whether you help or make it worse:

- **Retry immediately** → you hit the dependency again while it is still down, and add load exactly when it is struggling.
- **Retry at a fixed interval** → better, but if a thousand clients failed together, they all come back together, on every round.

## Exponential backoff

Wait longer after each failure: 100 ms, 200 ms, 400 ms, 800 ms... up to a cap. A struggling dependency gets progressively more room to recover.

## Jitter

Backoff alone still **synchronises** clients: everyone who failed at t=0 retries at exactly t=100, t=300, t=700. These waves are a *thundering herd* that can knock a recovering service over again. Adding randomness spreads the retries out.

| Strategy | Delay for attempt *n* | Character |
| --- | --- | --- |
| None | `min(cap, base · 2ⁿ)` | Synchronised waves |
| **Full jitter** | `random(0, exp)` | Best spread, least total load. The usual default |
| Equal jitter | `exp/2 + random(0, exp/2)` | Keeps a guaranteed minimum wait |
| Decorrelated | `min(cap, random(base, previous · 3))` | Grows from the previous delay rather than the attempt number |

## In this folder

| File | Role |
| --- | --- |
| `BackoffPolicy.php` | Calculates the delay before each retry, for every jitter strategy |
| `JitterStrategy.php` | `None`, `Full`, `Equal`, `Decorrelated` |
| `Retrier.php` | Runs an operation with retries: max attempts, which errors are retryable, injected sleep |
| `RetryBudget.php` | Caps retries as a fraction of normal traffic, to stop retry storms |

## What to notice

- **Only retry what can succeed.** A timeout or a 503, yes. A 400 or a validation error will fail identically every time.
- **Only retry idempotent operations** — or make them so with an [idempotency key](../../DataConsistency/Idempotency). The first attempt may have succeeded with the response lost.
- **Always cap attempts and delay.** Unbounded retries are an outage amplifier.
- **Retries multiply through layers.** Three layers each retrying three times turn one request into 27. Retry at one layer only, and use a **retry budget** so that retries never exceed, say, 10% of traffic.
- A [circuit breaker](../CircuitBreaker) is the complement: backoff slows down, the breaker stops altogether.

## Try it

```bash
php artisan test --filter=RetryWithBackoffTest
```
