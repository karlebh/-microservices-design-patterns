<?php

declare(strict_types=1);

namespace Patterns\BigData\FeatureStore;

use InvalidArgumentException;

/**
 * A minimal feature store.
 *
 * Every feature value is recorded with the time it became known (event
 * time). The offline store keeps the full history; the online store keeps
 * only the newest value per entity. Both are fed from the same records,
 * which is what keeps training and serving consistent.
 */
final class FeatureStore
{
    /** @var array<string, array{ttl: ?int}> feature name => definition */
    private array $registry = [];

    /** @var array<string, array<string, list<array{time: int, value: mixed}>>> feature => entity => history, oldest first */
    private array $offline = [];

    /** @var array<string, array<string, array{time: int, value: mixed}>> feature => entity => latest value */
    private array $online = [];

    /**
     * Define a feature. Definitions are shared: both the training pipeline
     * and the serving path refer to features by these names.
     *
     * @param  int|null  $ttl  how long a value stays usable after it was recorded (null = forever)
     */
    public function register(string $feature, ?int $ttl = null): void
    {
        $this->registry[$feature] = ['ttl' => $ttl];
    }

    /** Record that $feature had $value for $entity as of $eventTime. */
    public function ingest(string $feature, string $entity, int $eventTime, mixed $value): void
    {
        $this->assertRegistered($feature);

        $this->offline[$feature][$entity][] = ['time' => $eventTime, 'value' => $value];

        usort($this->offline[$feature][$entity], fn (array $a, array $b) => $a['time'] <=> $b['time']);
    }

    // ---- Training ----------------------------------------------------------------------

    /**
     * Build a training set with a POINT-IN-TIME join.
     *
     * For each example (an entity at a timestamp) every feature gets the
     * value that was known at that timestamp: the most recent one recorded
     * at or before it, and not older than the feature's TTL.
     *
     * @param  list<array{entity: string, time: int}>  $examples  extra keys (e.g. the label) are kept
     * @param  list<string>  $features
     * @return list<array<string, mixed>>
     */
    public function getHistoricalFeatures(array $examples, array $features): array
    {
        return array_map(function (array $example) use ($features) {
            foreach ($features as $feature) {
                $example[$feature] = $this->valueAsOf($feature, $example['entity'], $example['time']);
            }

            return $example;
        }, $examples);
    }

    /**
     * The WRONG way, kept for comparison: join the latest known value of
     * each feature regardless of the example's timestamp. Simple to write
     * in SQL, and it leaks the future into the training data.
     *
     * @param  list<array{entity: string, time: int}>  $examples
     * @param  list<string>  $features
     * @return list<array<string, mixed>>
     */
    public function naiveLatestJoin(array $examples, array $features): array
    {
        return array_map(function (array $example) use ($features) {
            foreach ($features as $feature) {
                $this->assertRegistered($feature);

                $history = $this->offline[$feature][$example['entity']] ?? [];
                $example[$feature] = $history === [] ? null : $history[array_key_last($history)]['value'];
            }

            return $example;
        }, $examples);
    }

    // ---- Serving -----------------------------------------------------------------------

    /**
     * Copy the newest value of every feature (as of $upTo) into the online
     * store. Runs on a schedule, or continuously from a stream.
     */
    public function materialize(int $upTo): void
    {
        foreach ($this->offline as $feature => $entities) {
            foreach ($entities as $entity => $history) {
                foreach (array_reverse($history) as $record) {
                    if ($record['time'] <= $upTo) {
                        $this->online[$feature][$entity] = $record;

                        break;
                    }
                }
            }
        }
    }

    /**
     * Low-latency lookup at prediction time: one key-value read per feature.
     *
     * @param  list<string>  $features
     * @return array<string, mixed> feature => value (null when missing or expired)
     */
    public function getOnlineFeatures(string $entity, array $features, int $now): array
    {
        $values = [];

        foreach ($features as $feature) {
            $this->assertRegistered($feature);

            $record = $this->online[$feature][$entity] ?? null;

            $values[$feature] = $record !== null && $this->isFresh($feature, $record['time'], $now)
                ? $record['value']
                : null;
        }

        return $values;
    }

    // ---- Internals ---------------------------------------------------------------------

    private function valueAsOf(string $feature, string $entity, int $time): mixed
    {
        $this->assertRegistered($feature);

        // Walk back from the newest record to the first one that is not in the future.
        foreach (array_reverse($this->offline[$feature][$entity] ?? []) as $record) {
            if ($record['time'] <= $time) {
                return $this->isFresh($feature, $record['time'], $time) ? $record['value'] : null;
            }
        }

        return null;
    }

    private function isFresh(string $feature, int $recordedAt, int $now): bool
    {
        $ttl = $this->registry[$feature]['ttl'];

        return $ttl === null || $now - $recordedAt <= $ttl;
    }

    private function assertRegistered(string $feature): void
    {
        if (! isset($this->registry[$feature])) {
            throw new InvalidArgumentException("Unknown feature [{$feature}]. Features must be registered before use.");
        }
    }
}
