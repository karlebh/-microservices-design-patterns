# Independent Deployability & Evolutionary Architecture

## Independent deployability

The defining property of a microservice: **you can release it without coordinating with anyone else.** If deploying service A requires deploying B at the same moment, you have a distributed monolith, whatever the diagram says.

What breaks it is almost always a **breaking change to a contract** (an API or event that other services consume). Two tools keep contracts safe:

### Consumer-driven contracts

Each consumer writes down exactly what it relies on ("from `GET /customers/{id}` I read `id` and `email`"). The provider runs **every consumer's contract against its new build** before deploying. A failing contract blocks the release — the provider learns it would break someone *before* production does. (This is what Pact does.)

### Expand / contract (parallel change)

How to make a breaking change without breaking anyone — in three independently deployable steps:

1. **Expand** — add the new thing next to the old (`full_name` alongside `name`).
2. **Migrate** — consumers switch over, one by one, whenever they like.
3. **Contract** — once no contract mentions the old thing, remove it.

## Evolutionary architecture

An architecture will change; the question is whether it *degrades* while doing so. A **fitness function** is an automated, objective check of an architectural property — a unit test for the architecture. Run in CI, it turns "we agreed services shouldn't depend on each other in circles" from a wiki page into a failing build.

Examples: no dependency cycles, the domain layer never imports infrastructure, no service has more than N direct dependencies, p99 latency under a budget.

## In this folder

| File | Role |
| --- | --- |
| `ConsumerContract.php` | What one consumer needs from one endpoint |
| `ContractVerifier.php` | Checks a provider's responses against all consumers' contracts: the deploy gate |
| `DependencyGraph.php` | Fitness functions over a dependency graph: cycles, forbidden dependencies, fan-out, blast radius |
| `SourceScanner.php` | Builds that graph from real PHP source (namespaces and `use` statements) |

## What to notice

- **Adding** a field never breaks a contract; **removing or retyping** one does. Consumers that only assert on what they use (tolerant readers) leave the provider free to evolve.
- The verifier tells you **who** still uses a field, i.e. exactly when the *contract* step is safe.
- The last test runs the fitness functions on **this repository**: every pattern folder must stay independent of the others, apart from a short list of intentional compositions. Add an import that violates that and the test fails.

## Try it

```bash
php artisan test --filter=EvolutionaryArchitectureTest
```
