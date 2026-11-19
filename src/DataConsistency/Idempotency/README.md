# Idempotent Consumer / Idempotency Keys

## The problem

Networks lose responses, clients time out and retry, brokers redeliver. So the same request or message **will** arrive more than once. If handling it has a side effect (create an order, charge a card), a retry must not repeat the side effect.

## The idea

Make "the same operation" recognisable, and remember that you already did it.

**Idempotency keys (client → API).** The client generates a unique key per *intent* and sends it in the `Idempotency-Key` header. The server stores the key with the response. A retry with the same key gets the stored response back instead of a second execution.

**Idempotent consumer (broker/webhook → service).** Every message carries a unique id. The consumer records processed ids in a table **in the same transaction** as the business change. A redelivery finds its id already recorded and is acknowledged without being re-applied.

Both rely on the same trick: a **unique constraint** decides the winner, so it is safe under concurrency.

## In this folder

| File | Role |
| --- | --- |
| `Http/OrderController.php` | `POST /api/orders` protected by an `Idempotency-Key` header |
| `Models/IdempotencyKey.php` | Stored key → status, request fingerprint, saved response |
| `IdempotentConsumer.php` | Reusable "run at most once per message id" helper |
| `Webhooks/WebhookController.php` | `POST /api/webhooks/orders` — a receiver that tolerates redelivery |
| `Webhooks/ProcessWebhookCommand.php` | `php artisan webhooks:process` — an at-least-once *sender* (retries with backoff), i.e. the reason receivers must be idempotent |
| `Migrations/` | `idempotency_keys`, `orders`, `processed_messages`, `webhook_deliveries` |

## What to notice

- The key is **claimed with an INSERT before doing the work**. Of N concurrent requests with the same key, the primary key lets exactly one through.
- A key is bound to a **request fingerprint**: reusing it with a different body is a client bug and is rejected (422), not silently answered with the old response.
- A key belongs to **one caller**. Another user presenting the same key never sees the stored response.
- Duplicates are answered with **success** (`200`), not an error — the sender's goal is "make sure it happened", and it did.
- Here the work is a local DB write, so claim + work + result share one transaction. If the work calls an external API, commit the claim first (`processing`), then do the work, then store the result — and decide what a stale `processing` row means after a crash.
- Keys should expire (24h is typical); otherwise the table grows forever.

## Try it

```bash
php artisan test --filter=IdempotencyTest
```
