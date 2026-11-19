# Domain-Driven Design: Bounded Contexts, Aggregates, Ubiquitous Language

DDD is a way to decide **where the boundaries go** — which is the hard part of microservices. This folder models a small shop as two bounded contexts and shows the main building blocks in code.

## Ubiquitous language

Developers and domain experts use the **same words**, and those words appear in the code. If the sales team says "place an order", the method is `place()`, not `setStatus(2)`.

## Bounded contexts

One model for the whole company never works, because the same word means different things to different teams. A bounded context is the area inside which a model, and its language, is consistent.

| Real-world thing | In **Sales** | In **Shipping** |
| --- | --- | --- |
| What the customer bought | an `Order` with `OrderLine`s and a total | a `Shipment` made of `Parcel`s |
| A product | something with a **price** | something with a **weight** |
| The person | a customer (who pays) | a recipient (where it goes) |
| Finishing | `place()` | `dispatch()` |

Each context is a natural candidate for a service: its own model, its own data, its own team.

## Aggregates

An aggregate is a cluster of objects that must be **consistent together**, changed only through its **root**.

- Outside code holds a reference to the root only (`Order`), never to the inside (`OrderLine`).
- The root enforces the **invariants** ("a placed order can't change", "a shipment can't exceed 30 kg").
- One transaction changes **one aggregate**. Other aggregates are referenced **by id** and updated eventually, via domain events.

## In this folder

| File | Building block |
| --- | --- |
| `Sales/Money.php` | **Value object** — no identity, immutable, compared by value |
| `Sales/Order.php` | **Aggregate root** — guards invariants, records domain events |
| `Sales/OrderLine.php` | **Entity** inside the aggregate, reachable only through the root |
| `Sales/OrderPlaced.php` | **Domain event** — a fact in the ubiquitous language; the context's published contract |
| `Sales/OrderRepository.php` | **Repository** — stores and loads whole aggregates |
| `Shipping/Shipment.php` | A different aggregate, in a different context, with its own rules |
| `Shipping/SalesTranslator.php` | **Anti-corruption layer** — translates Sales' event into Shipping's language at the border |

## What to notice

- `Shipping` never imports `Sales\Order`. It depends only on the published event, and only inside the translator. Sales can refactor its model freely.
- The two contexts use different units for a "product" (price vs weight) without any conflict, because neither model tries to serve both.
- Aggregates are kept **small**. A big aggregate means big transactions and lock contention.

## Try it

```bash
php artisan test --filter=DomainDrivenDesignTest
```
