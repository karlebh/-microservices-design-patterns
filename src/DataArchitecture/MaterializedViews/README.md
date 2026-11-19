# Materialized Views & Incremental Computation

## The problem

A dashboard shows "orders and revenue per customer". As a normal view, that is a query that re-reads and re-aggregates the whole `orders` table on every page load. With a hundred million orders, it is too slow to run on demand.

## Materialized view

Run the query **once** and **store the result** as a table. Reading it is now a simple lookup. The catch: the stored result goes stale as soon as the base table changes, so it has to be refreshed.

| Refresh | Work per refresh | Freshness |
| --- | --- | --- |
| **Full** | Recompute from the whole table: O(table size) | Stale between refreshes (hourly, nightly) |
| **Incremental** | Apply only what changed: O(size of the change) | Up to date after every change |

## Incremental view maintenance

Don't recompute; **adjust**. Every change to the base table is a *delta*, and for most aggregates the effect of a delta on the result can be calculated directly:

```
insert order(customer=ada, amount=50)   →   ada.count += 1,  ada.sum += 50
delete order(customer=ada, amount=50)   →   ada.count -= 1,  ada.sum -= 50
update = delete the old row + insert the new row
```

`COUNT` and `SUM` can be undone by subtraction. `MIN` and `MAX` cannot — after deleting the current minimum you don't know the next one — so the view has to keep more state (here: how many rows have each value).

This is the idea behind streaming databases and incremental engines (Materialize, RisingWave, ksqlDB tables, Flink SQL, `pg_ivm`), and it is the same thing a [CQRS read model](../../DataConsistency/Cqrs) does by hand.

## In this folder

| File | Role |
| --- | --- |
| `Table.php` | A base table that publishes every insert, update and delete as a change |
| `MaterializedView.php` | A stored `GROUP BY` aggregate (count, sum, avg, min, max) with an optional `WHERE`, supporting full refresh and incremental maintenance |

## What to notice

- The cost counters: a full refresh processes **every row**; an incremental update processes **one or two**, no matter how large the table is.
- A randomized test applies hundreds of mixed changes and checks that the incrementally maintained view equals a from-scratch recomputation every time. That equivalence is the whole correctness criterion.
- An update can move a row from one group to another, or in and out of the `WHERE` filter. Treating it as delete + insert handles every case.
- A group whose last row is deleted disappears.

## Try it

```bash
php artisan test --filter=MaterializedViewsTest
```
