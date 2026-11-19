# Graceful Degradation / Fallbacks

## The problem

A product page calls five services. The recommendation service is down. Should the customer get an error page?

In a distributed system something is *always* partly broken. If every dependency failure becomes a user-facing failure, your availability is the **product** of all your dependencies' availabilities — worse than any single one of them.

## The idea

Decide **in advance** what a reduced-but-useful answer looks like for each dependency, and return that instead of an error. The feature gets worse; the system keeps working.

| Fallback | Example |
| --- | --- |
| **Stale data** | Show the last price list we successfully fetched |
| **A cheaper source** | Personalised recommendations → bestsellers |
| **A static default** | An empty list, a placeholder, a generic message |
| **Leave it out** | Render the page without the reviews section |
| **Defer** | Accept the order now, send the confirmation email later |

And under load, degrade **on purpose**: switch off expensive optional features so the essential ones keep their capacity.

## In this folder

| File | Role |
| --- | --- |
| `FallbackChain.php` | Try the primary, then each fallback in order; report which one answered |
| `Outcome.php` | The value plus the truth about it: its source, whether it is degraded, what failed |
| `LastKnownGood.php` | Remembers the last successful answer so it can be served when the live call fails |
| `FeatureDegrader.php` | Turns optional features off, in a fixed order, as load rises |
| `LoadLevel.php` | `Normal`, `Elevated`, `High`, `Critical` |

## What to notice

- **Not everything should have a fallback.** A missing recommendation can be an empty list; a missing *price* must be an error. A silent default for something essential is a bug with a delay on it. The chain rethrows when nothing can answer.
- **Say that it is degraded.** The outcome carries `degraded` and `source`, so the UI can mark stale data and so dashboards show that users are being served fallbacks. A fallback nobody can see hides outages.
- **Fallbacks must be simpler than the thing they replace** — no network calls to another fragile service. And they must be **tested**, because they only run on the worst day.
- **Order of shedding is a product decision**, made calmly beforehand: recommendations before reviews, reviews before search, checkout never.
- Fallbacks are what a [circuit breaker](../CircuitBreaker) or a full [bulkhead](../Bulkhead) should lead to, instead of an exception.

## Try it

```bash
php artisan test --filter=GracefulDegradationTest
```
