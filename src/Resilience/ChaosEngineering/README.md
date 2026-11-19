# Chaos Engineering (Fault Injection)

## The problem

You added timeouts, retries, fallbacks and circuit breakers. Do they work? Those code paths run only when something fails — which means in normal operation they are **never executed**, never observed, and quietly rot. You find out whether they work during a real outage, at the worst possible time.

## The idea

Cause the failures yourself, **on purpose and under control**, and watch what happens. Chaos engineering is an experiment, with the discipline of one:

1. **Define steady state** — a measurable signal that means "users are fine" (e.g. 99% of product pages render).
2. **State a hypothesis** — "if the recommendation service fails, the steady state holds".
3. **Inject a realistic fault** — errors, latency, a dead dependency.
4. **Measure** — did the steady state hold?
5. **Roll back** the fault, always.
6. **Learn** — a refuted hypothesis is the valuable outcome: you found a weakness before your customers did.

It is not "randomly breaking production". The **blast radius** is kept small (one dependency, a fraction of requests), there is an **abort condition**, and there is a **kill switch**.

## In this folder

| File | Role |
| --- | --- |
| `FaultInjector.php` | Wraps calls to dependencies and, when told to, injects errors or latency for a chosen share of requests |
| `ChaosExperiment.php` | Runs the experiment loop: baseline → inject → measure → roll back → verify recovery |
| `ExperimentReport.php` | The result: the three measurements and whether the hypothesis held |
| `InjectedTimeout.php` | What a caller sees when injected latency exceeds its timeout |

## What to notice

- **Never start on an unhealthy system.** If the baseline already violates the steady state, the experiment refuses to run.
- **Latency is the more revealing fault.** A dependency that errors fails fast; one that is *slow* ties up your resources. Injected latency only hurts callers whose timeout is shorter than the delay — which tests that the timeout exists.
- **The fault is removed in a `finally`**, whatever the measurement does.
- The same experiment is run against a version of the code **with** a fallback and one **without**: same fault, opposite verdicts.
- Start in a test environment, then production with a tiny blast radius, and automate the experiments that pass so regressions are caught.

## Try it

```bash
php artisan test --filter=ChaosEngineeringTest
```
