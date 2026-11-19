# Vector Clocks & Lamport Timestamps (Logical Time)

## The problem

"Which happened first?" sounds like a job for timestamps. But every machine has its own clock, and those clocks disagree by milliseconds to seconds, drift, and occasionally jump backwards (NTP corrections). Two events one millisecond apart on different machines cannot be ordered by wall-clock time. Ordering by it silently loses data (see last-write-wins in [Failover](../../Resilience/Failover)).

What we can know for certain is **causality**: a message is sent before it is received; events on one machine happen in sequence. Logical clocks capture exactly that, with counters instead of time.

## Lamport timestamps

Each process keeps one counter.

- On a local event or before sending: `counter += 1`
- On receiving a message stamped `t`: `counter = max(counter, t) + 1`

**Guarantee:** if A caused B (A *happened before* B), then `L(A) < L(B)`.

**Limit:** the reverse is not true. `L(A) < L(B)` does not tell you whether A caused B or whether they are unrelated. Lamport timestamps give a consistent *total order* (break ties by process id) but cannot detect concurrency.

## Vector clocks

Each process keeps one counter **per process**: `{A: 2, B: 0, C: 1}` means "I have seen 2 events from A, none from B, 1 from C".

- On a local event: increment your own entry
- On receive: take the element-wise maximum of both vectors, then increment your own entry

Comparing two vectors:

| Relation | Meaning |
| --- | --- |
| every entry of X ≤ Y, and one is smaller | X **happened before** Y |
| every entry of X ≥ Y, and one is larger | X **happened after** Y |
| some entries smaller, some larger | X and Y are **concurrent** — neither knew of the other |

So vector clocks answer the question Lamport timestamps cannot: *were these two updates made independently?* That is how Dynamo-style stores detect conflicting writes.

The cost: the vector grows with the number of processes.

## In this folder

| File | Role |
| --- | --- |
| `LamportClock.php` | The single-counter clock |
| `VectorClock.php` | An immutable vector clock with `increment`, `merge` and `compare` |
| `Ordering.php` | `Before`, `After`, `Equal`, `Concurrent` |
| `Process.php` | A process that stamps local events, sends and receives with both clocks |

## Try it

```bash
php artisan test --filter=LogicalClocksTest
```
