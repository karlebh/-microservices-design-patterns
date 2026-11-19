# Dead Letter Queues (DLQ) & Poison Pill Handling

## The problem

A consumer fails to process a message, so the message goes back to the queue to be retried. That is correct for a temporary problem (the database was briefly down). But some messages can **never** succeed: malformed JSON, a reference to a deleted record, a payload that triggers a bug. Such a message is a **poison pill**:

- it is redelivered and fails **forever**, burning CPU,
- and in an ordered queue it sits at the head and **blocks every message behind it**.

## The idea

Count deliveries. When a message has failed too many times, stop retrying and move it to a separate queue — the **dead letter queue** — along with *why* it failed. The main queue keeps flowing; the bad message is kept for a human (or a tool) to inspect.

```
            fails, attempt < max
          ┌──────────────────────┐
          ▼                      │
main queue ──► consumer ─────────┤
                                 │ fails, attempt = max  (or the error is permanent)
                                 ▼
                        dead letter queue ──► inspect, fix, REDRIVE back to the main queue
```

## In this folder

| File | Role |
| --- | --- |
| `ResilientQueue.php` | A queue that counts receives, retries transient failures, dead-letters after `maxReceiveCount`, and can redrive |
| `DeadLetter.php` | A parked message with its diagnosis: payload, attempts, last error |
| `PermanentFailure.php` | Exception a handler throws to say "retrying will never help" |

## What to notice

- **Tell transient from permanent.** A timeout deserves retries; a validation error does not. `PermanentFailure` sends a message to the DLQ on the first attempt instead of wasting the retry budget.
- **Keep the diagnosis.** A DLQ entry without the error and attempt count is just a pile of mystery messages.
- **Redrive** after fixing the cause: the dead letters go back to the main queue and get a fresh set of attempts.
- **A DLQ needs an owner and an alarm.** A dead letter is a failed business operation. If nobody looks, the DLQ is a slower way of losing data.
- Dead-lettering **breaks ordering** for that message. If strict order matters, you may prefer to stop the partition and page someone.
- Usually combined with a delay between attempts: see [Retry with Backoff](../RetryWithBackoff).

## Try it

```bash
php artisan test --filter=DeadLetterQueueTest
```
