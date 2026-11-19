# OLAP vs. OLTP vs. HTAP

Two kinds of workload ask opposite things of a database.

| | OLTP — transactional | OLAP — analytical |
| --- | --- | --- |
| Typical query | "Get order 42", "mark it paid" | "Revenue per country last quarter" |
| Rows touched | A few, by key | Millions |
| Columns touched | All of the row | A handful out of dozens |
| Writes | Many small, concurrent | Bulk loads |
| Priority | Latency per transaction, correctness | Throughput of large scans |
| Storage | **Row-oriented** | **Column-oriented** |
| Examples | PostgreSQL, MySQL | ClickHouse, DuckDB, BigQuery, Snowflake, Redshift |

## Why the storage layout decides it

**Row store:** all the values of one row sit next to each other.

```
[1, ada, UK, paid, 50] [2, bob, NG, paid, 20] [3, cy, UK, new, 70]
```

Fetching or changing a row is one read. But summing `amount` drags every column of every row through memory to use one of them.

**Column store:** all the values of one column sit next to each other.

```
id:      [1, 2, 3]
country: [UK, NG, UK]
amount:  [50, 20, 70]
```

Summing `amount` reads only `amount`. And because a column holds similar values, it **compresses** extremely well (run-length, dictionary encoding). But reading or writing one whole row touches every column.

## HTAP — hybrid

Traditionally the OLTP database is copied into a warehouse by a nightly ETL job, so analytics runs on yesterday's data. An HTAP system keeps **both layouts of the same data**: transactions go to a row store, and changes flow continuously into a column store, with a small in-memory *delta* covering what hasn't been merged yet. Analytics is then fresh, and doesn't slow down transactions. (TiDB + TiFlash, SingleStore, SQL Server columnstore indexes.)

## In this folder

| File | Role |
| --- | --- |
| `RowStore.php` | Row-oriented storage, counting the cells and seeks each operation needs |
| `ColumnStore.php` | Column-oriented storage, with run-length and dictionary encoding |
| `HtapStore.php` | Row store for transactions + column store for analytics + a delta that keeps analytics fresh |

The `duckdb` connection in `config/database.php` is a real column-oriented engine; this folder shows why such engines exist.

## Try it

```bash
php artisan test --filter=OlapOltpHtapTest
```
