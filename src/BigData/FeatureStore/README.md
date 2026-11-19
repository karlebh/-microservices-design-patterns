# Feature Stores

## The problem

A machine-learning model is a function of **features**: `orders_last_30d`, `avg_basket_value`, `days_since_signup`. Features are needed in two places, with opposite requirements:

- **Training** needs the value each feature had **at the moment of every historical example**, for millions of rows. Throughput matters; latency doesn't.
- **Serving** needs the **current** value for one user, in a few milliseconds, while a request waits.

Without a shared system, these are built twice — a batch SQL job for training and application code for serving — and three things go wrong:

1. **Training/serving skew.** The two implementations differ subtly, so the model sees different inputs in production than it was trained on.
2. **Data leakage.** A training join that picks the *latest* feature value gives the model information from after the event it is predicting. Offline metrics look superb; production accuracy collapses.
3. **Duplication.** Every team recomputes the same features.

## The idea

A feature store is the single place where features are **defined once**, and served two ways from the same data:

```
                      ┌─► OFFLINE store: full history, timestamped  ─► training sets (point-in-time joins)
feature pipelines ───►│
                      └─► ONLINE store: latest value per entity     ─► low-latency lookups at prediction time
```

- **Point-in-time join:** for a training example at time *t*, take the most recent feature value with a timestamp **≤ t**. Never anything later.
- **Materialization:** copy the latest values from the offline store into the online store.
- **TTL:** a feature value older than its time-to-live is treated as missing rather than served stale.

## In this folder

| File | Role |
| --- | --- |
| `FeatureStore.php` | Feature registry, offline history, point-in-time joins, materialization, online lookups with TTL — and a deliberately leaky join for comparison |

Real systems: Feast, Tecton, Hopsworks, Vertex AI / SageMaker Feature Store.

## Try it

```bash
php artisan test --filter=FeatureStoreTest
```
