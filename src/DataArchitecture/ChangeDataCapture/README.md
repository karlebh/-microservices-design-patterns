# Change Data Capture (CDC)

## The problem

Data lives in the service's database, but other systems need to know when it changes: a search index, a cache, a data warehouse, other services. Options that don't work well:

- **Dual writes** from application code (write DB, then write the index) — the two drift apart on any failure.
- **Polling** (`WHERE updated_at > ?`) — slow, heavy, misses deletes and intermediate states.

## The idea

The database already writes every committed change to its **transaction log** (Postgres WAL, MySQL binlog) for its own crash recovery and replication. CDC **tails that log** and turns each entry into an event:

```
app ──► DATABASE ──► transaction log ──► CDC connector ──► search index / cache / warehouse / broker
```

The application doesn't change at all, and nothing is missed: if it was committed, it is in the log, in commit order.

A change event carries the row **before** and **after**:

| op | meaning | before | after |
| --- | --- | --- | --- |
| `c` | insert | – | new row |
| `u` | update | old row | new row |
| `d` | delete | old row | – |
| `r` | snapshot read | – | existing row |

## In this folder

| File | Role |
| --- | --- |
| `Database.php`, `Transaction.php` | A tiny transactional store that appends every **committed** change to a log with an increasing LSN |
| `ChangeEvent.php` | One log entry in Debezium-like shape |
| `CdcConnector.php` | Tails the log from a saved offset and feeds sinks; can take an initial snapshot |
| `TableReplica.php` | A sink that maintains a copy of a table from change events |

## What to notice

- **Only committed data** is captured; a rolled-back transaction leaves no events.
- **Snapshot, then stream:** existing rows are emitted first (`r`), then the connector continues from the log position recorded at snapshot time, so there is no gap.
- The connector's whole state is **one number** (the offset). It advances only after the sinks accepted an event, so a crash causes **redelivery, never loss** → sinks must be idempotent.
- Deletes are visible, unlike with `updated_at` polling.
- Costs: consumers become coupled to your table schema, and a stalled connector makes the database retain log files.

Real tools: Debezium, AWS DMS, Maxwell. Used together with an outbox table in [Outbox + CDC](../../DataConsistency/OutboxCdc).

## Try it

```bash
php artisan test --filter=ChangeDataCaptureTest
```
