# Service Granularity Trade-offs & Decomposition Strategies

## The problem

"How big should a service be?" has no number for an answer. Too **coarse** and you still have a monolith: teams collide, everything scales and deploys together. Too **fine** and you have a *distributed monolith*: every request hops through ten services, you need sagas for things that used to be a transaction, and a change still touches five repositories.

The right size is the result of weighing forces that pull in opposite directions.

## Forces that pull apart — *disintegrators*

Reasons to **split** two pieces of functionality into separate services:

| Force | Question |
| --- | --- |
| **Code volatility** | Does one change far more often? Then it shouldn't drag the stable one through every release. |
| **Scalability** | Does one need far more capacity? Then scale only that. |
| **Fault tolerance** | Must one keep running when the other crashes? |
| **Security** | Does one handle sensitive data that needs tighter access? |

## Forces that pull together — *integrators*

Reasons to **keep** them in one service:

| Force | Question |
| --- | --- |
| **Database transactions** | Must they commit or roll back together? Splitting replaces ACID with a saga. |
| **Workflow chattiness** | Would they call each other many times per request? That is latency and coupled availability. |
| **Shared data** | Do both write the same tables? Then neither really owns that data. |

Split only when a disintegrator applies **and** no integrator outweighs it. No reason to split ⇒ don't.

## Decomposition strategies

Where to draw the first boundaries:

- **By business capability** — what the business *does* (ordering, payments, shipping). Stable, and matches how teams are organised.
- **By subdomain** — the [bounded contexts](../DomainDrivenDesign) of DDD.
- **By data ownership** — functionality that writes the same data belongs together. A quick, mechanical sanity check for any proposed split.

## In this folder

| File | Role |
| --- | --- |
| `Capability.php` | A piece of functionality and the characteristics that matter for sizing |
| `GranularityAnalyzer.php` | Weighs disintegrators against integrators for a pair and returns a verdict **with its reasons** |
| `GranularityAdvice.php` | The verdict and the forces behind it |
| `DecompositionPlanner.php` | Proposes service boundaries by business capability or by data ownership |

The analyzer is a way to make the reasoning explicit and repeatable, not an oracle: the thresholds are judgment calls.

## Try it

```bash
php artisan test --filter=ServiceGranularityTest
```
