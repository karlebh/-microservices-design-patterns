# Request-Reply over Async Messaging

## The problem

Messaging is one-way: you send and move on. But sometimes the sender needs an **answer** ("what is the price?", "is this card valid?") and still wants what a broker gives over a direct HTTP call: the responder can be temporarily down, can be scaled out behind a queue, and neither side needs to know the other's address.

## The idea

Build a two-way conversation from two one-way messages. The request carries two extra headers:

- **`reply_to`** — the queue where the requester is listening for answers
- **`correlation_id`** — a unique id for *this* request

The responder copies the correlation id onto its reply and sends it to `reply_to`. The requester matches each incoming reply to the waiting request by that id.

```
requester ── request {correlation_id: 7, reply_to: replies.web-1} ──► [requests] ──► responder
requester ◄── reply   {correlation_id: 7, body: ...} ◄── [replies.web-1] ◄───────────┘
```

## In this folder

| File | Role |
| --- | --- |
| `MessageBus.php` | Named in-memory queues |
| `Requester.php` | Sends requests, owns a private reply queue, matches replies by correlation id, enforces timeouts |
| `PendingReply.php` | The handle the caller holds while waiting: pending → fulfilled or timed out |
| `Responder.php` | Takes requests from the shared request queue and replies to each one's `reply_to` |

## What to notice

- **Replies don't arrive in request order.** The correlation id is the only thing that pairs them up.
- **Always have a timeout.** The responder may be down or the reply lost; without a deadline the caller waits forever. A reply that shows up after the timeout is discarded.
- **One reply queue per requester instance**, otherwise instance A would consume replies meant for instance B.
- Responders are just [competing consumers](../CompetingConsumers): add more to scale.
- A timeout doesn't tell you whether the request was processed. Retrying safely requires the responder to be [idempotent](../../DataConsistency/Idempotency).
- If the caller blocks waiting, you've recreated synchronous coupling with extra hops. Prefer this when the caller can do something else meanwhile; otherwise consider plain events.

## Try it

```bash
php artisan test --filter=RequestReplyTest
```
