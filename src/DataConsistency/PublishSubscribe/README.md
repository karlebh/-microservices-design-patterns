# Publish-Subscribe & Event Notification vs Event-Carried State Transfer

## Publish-subscribe

A publisher emits an event to a **topic** without knowing who listens. Every subscriber gets its own copy. Adding a new consumer requires no change to the publisher — that is the decoupling.

```
                         ┌─► shipping
customers ─► [topic] ────┼─► billing
                         └─► (whoever subscribes next year)
```

## What do you put *in* the event?

That choice has two classic answers, with opposite trade-offs.

### Event notification — "something changed"

```json
{ "type": "customer.address_changed", "customer_id": 42 }
```

The event is a thin pointer. A consumer that needs details **calls the source back**.

- ✔ small events, data is always fresh, the source stays the single owner
- ✘ consumers are coupled to the source's API and **availability**; a burst of events becomes a burst of callbacks

### Event-carried state transfer (ECST) — "here is the new state"

```json
{ "type": "customer.address_changed", "customer_id": 42, "name": "Ada", "address": "1 Main St" }
```

The event carries everything a consumer needs. Each consumer keeps its **own local copy**.

- ✔ consumers never call back: faster, and they keep working when the source is down
- ✘ bigger events, data is duplicated and only **eventually consistent**, and the event payload becomes a public contract

## In this folder

| File | Role |
| --- | --- |
| `PubSubBroker.php` | Topics with fan-out; one failing subscriber doesn't affect the others |
| `CustomerService.php` | The source of truth; publishes thin or fat events depending on `EventStyle` |
| `ShippingService.php` | Notification consumer: reacts by calling the customer API |
| `BillingService.php` | ECST consumer: maintains a local replica from event payloads |

## What to notice

- With the customer service **down**, shipping can't process its event, while billing keeps answering from its replica. That is the availability difference in one test.
- Count the API calls: notification costs one callback per event per consumer; ECST costs zero.
- A common middle ground: a notification that includes just the fields most consumers need.

## Try it

```bash
php artisan test --filter=PublishSubscribeTest
```
