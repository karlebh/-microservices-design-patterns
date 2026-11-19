# Kappa vs. Lambda Architecture

Both answer the same question: how do you serve results that are **complete and correct over all history** and also **fresh to the last few seconds**?

## Lambda — two pipelines

```
                 ┌─► BATCH layer  (recompute everything, every N hours)  ─► batch view ──┐
events ─► log ───┤                                                                       ├─► serving layer: merge
                 └─► SPEED layer  (incremental, only events since the last batch) ─► realtime view ┘
```

- The **batch layer** periodically recomputes the result from the full, immutable master dataset. Slow, but simple and trustworthy; it can dedupe, join and fix anything.
- The **speed layer** processes new events as they arrive, to cover the hours the batch layer hasn't reached yet. Fast, often approximate.
- A **query** combines both views. Each batch run replaces the speed layer's provisional numbers with exact ones.

The cost: **the same logic is implemented twice**, in two different systems, and the two must give the same answers. They drift.

## Kappa — one pipeline

```
events ─► log (kept forever / long retention) ─► STREAM processor ─► view
```

Everything is a stream. There is no batch layer. To change the logic or fix a bug, start a **second instance** of the stream job with the new code, let it **replay the log from the beginning** into a new view, and switch readers over when it has caught up.

One codebase, one system. The requirements: a replayable log, and a stream processor capable of everything the batch job did.

| | Lambda | Kappa |
| --- | --- | --- |
| Code paths | Two (batch + streaming) | One |
| Reprocessing | Built in: the next batch run | Replay the log through a new job |
| Operational surface | Two systems + a merge layer | One |
| Good when | Batch and streaming logic genuinely differ, or huge historical recomputations | The stream processor can express the whole job |

## In this folder

| File | Role |
| --- | --- |
| `EventLog.php` | The immutable, replayable master dataset |
| `LambdaArchitecture.php` | Batch view + speed view + merging query |
| `KappaArchitecture.php` | One streaming job; reprocessing by replaying into a new view version |

Both count page views per page; the events include duplicates and bot traffic so that "accuracy" has something to mean.

## Try it

```bash
php artisan test --filter=KappaVsLambdaTest
```
