# Stream Processing (Flink, Spark Streaming, ksqlDB, Beam)

## The problem

A stream never ends, so you can't "wait for all the data and then aggregate". And events don't arrive in the order they happened: a phone goes through a tunnel and uploads its events ten minutes late; partitions are read at different speeds.

Stream processors (Flink, Beam, Kafka Streams/ksqlDB, Spark Structured Streaming) are built on a small set of concepts that deal with exactly that.

## The concepts

**Event time vs processing time.** *Event time* is when something happened (a timestamp inside the event). *Processing time* is when the system got around to it. Counting "clicks per minute" by processing time puts late events in the wrong minute; results then depend on network delays instead of on reality. Correct results need event time.

**Windows** cut the endless stream into finite pieces you can aggregate:

| Window | Shape | Example |
| --- | --- | --- |
| **Tumbling** | Fixed size, no overlap: `[0-60) [60-120)` | Requests per minute |
| **Sliding** (hopping) | Fixed size, overlapping: every 30 s, the last 60 s | Moving average |
| **Session** | Grows while events keep coming; closes after a gap of inactivity | One user visit |

**Watermarks.** With event time, how do you know a window is *complete*? You can't, for sure. A watermark is the processor's estimate: "I have probably seen everything up to time T" — typically `the highest event time seen − an allowed delay`. When the watermark passes the end of a window, the window's result is emitted.

**Late data.** An event that arrives after its window was emitted is *late*. Options: update the result (within an *allowed lateness*), or route it to a side output — never silently lose it.

**State.** Windows, counts and joins are state that must survive restarts; real engines checkpoint it and can give exactly-once results (see [Delivery Semantics](../../DataConsistency/DeliverySemantics)).

## In this folder

| File | Role |
| --- | --- |
| `WindowAssigner.php` | Interface: which window(s) does an event time belong to? |
| `TumblingWindows.php`, `SlidingWindows.php` | The two fixed-shape assigners |
| `WindowedCounter.php` | A keyed, event-time windowed count with watermarks, allowed lateness and a side output for late events |
| `SessionWindows.php` | Session windows: gap-based, merging |

## Try it

```bash
php artisan test --filter=StreamProcessingTest
```
