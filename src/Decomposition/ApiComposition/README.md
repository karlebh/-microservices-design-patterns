# API Composition vs. Database per Service

## Database per service

Each microservice **owns its data** and nobody else may touch its tables. Other services go through its API.

Why: a shared database is hidden coupling. Any service can break another by changing a column, nobody can deploy or scale independently, and one slow query hurts everyone. Private data is what makes a service independently changeable.

## The problem it creates

In the monolith, "show order 42 with the customer's name and the product titles" was one SQL `JOIN`. Now `orders`, `customers` and `products` live in three databases owned by three services. **You can't join across them.**

## API composition

Do the join in application code. A **composer** (often an API gateway or a BFF) calls each owning service and merges the results in memory:

```
                      ┌─► Order service     (orders DB)
client ─► COMPOSER ───┼─► Customer service  (customers DB)
                      └─► Product service   (products DB)
              └─ joins the three answers ─┘
```

## In this folder

| File | Role |
| --- | --- |
| `Services/OrderService.php`, `CustomerService.php`, `ProductService.php` | Three services, each the only owner of its own data |
| `OrderDetailsComposer.php` | Fetches from all three and performs the join in memory |

## What to notice

- **Batch, don't loop.** The composer asks the product service for all SKUs in **one** call (`getMany`). Calling it once per order line is the distributed N+1 problem.
- **Partial failure is the normal case.** With N services, something is often down. The composer returns what it has and marks the result `complete: false` rather than failing the page.
- **No transaction, no snapshot.** The three reads happen at slightly different times, so the result can be momentarily inconsistent.
- **Availability multiplies.** Three services at 99.9% give ~99.7% for a composition that needs all of them.

## When composition is the wrong tool

It works for simple lookups by id. It is a poor fit for queries that filter, sort or aggregate across services ("customers who bought X, ordered by total spend") — that would mean pulling whole datasets into memory. For those, keep a pre-joined **read model** updated by events: see [CQRS](../../DataConsistency/Cqrs) and [Materialized Views](../../DataArchitecture/MaterializedViews).

## Try it

```bash
php artisan test --filter=ApiCompositionTest
```
