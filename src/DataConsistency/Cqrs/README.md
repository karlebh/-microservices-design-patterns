# CQRS (Command Query Responsibility Segregation)

## The problem

One model is asked to do two jobs with opposite needs:

- **Writes** want a normalized model that protects business rules ("SKU is unique", "stock can't go negative").
- **Reads** want whatever shape the screen needs ("name, formatted price, stock badge"), fast, usually joined and pre-computed.

Serving both from the same tables means every read pays for joins and formatting, and every schema change is a tug-of-war between the two.

## The idea

Split them. **Commands** change state and return nothing. **Queries** return data and change nothing. Each side gets its own model:

```
Command ─► CommandBus ─► handler ─► WRITE MODEL (rules, normalized)
                                        │ events
                                        ▼
                                    Projector ─► READ MODEL (denormalized) ◄─ Query
```

## In this folder

| File | Role |
| --- | --- |
| `Commands/*.php` | Intent as data: `CreateProduct`, `ChangePrice`, `AdjustStock` |
| `CommandBus.php` | Routes a command object to its handler |
| `Write/ProductCommandHandler.php` | The write side: enforces invariants, stores normalized rows, emits events |
| `Read/ProductProjector.php` | Turns events into read-model rows — immediately, or queued (async) |
| `Read/ProductReadModel.php` | The query side: rows already shaped for the UI |

## What to notice

- The read model stores things the write model never would: a formatted price string and a stock label. Queries do **no computation**, they just look rows up.
- With an **async** projector the read side lags behind the write side: *eventual consistency*. A user may not see their own change for a moment — plan the UX for it (or read your own writes from the write side).
- The read model is **disposable**: drop it and replay the events to rebuild, or build a second, differently-shaped one.
- CQRS does **not** require event sourcing, two databases, or a message bus. Start with two models in one database; split further only when the load says so.
- It adds moving parts. For simple CRUD it is overkill.

## Try it

```bash
php artisan test --filter=CqrsTest
```
