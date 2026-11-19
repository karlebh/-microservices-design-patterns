<?php

declare(strict_types=1);

namespace Patterns\BigData\KappaVsLambda;

/**
 * Lambda architecture: page views per page, computed by two pipelines.
 *
 * Note that "count page views" is written twice below, once per layer,
 * and that the two versions are not identical: the batch job deduplicates
 * events, the speed layer does not (it sees one event at a time and keeps
 * no history). That gap is typical, and it is why the batch layer
 * periodically overrides what the speed layer said.
 */
final class LambdaArchitecture
{
    /** @var array<string, int> exact counts up to $batchOffset */
    private array $batchView = [];

    /** @var array<string, int> provisional counts for events after $batchOffset */
    private array $realtimeView = [];

    /** How much of the log the last batch run covered. */
    private int $batchOffset = 0;

    public int $batchRuns = 0;

    public function __construct(private readonly EventLog $log) {}

    /** A new event arrives: store it, and let the speed layer react immediately. */
    public function ingest(array $event): void
    {
        $this->log->append($event);

        // ---- SPEED LAYER: implementation #2 of the counting logic ----
        // Incremental and cheap. It cannot tell a duplicate from a new event.
        $this->realtimeView[$event['page']] = ($this->realtimeView[$event['page']] ?? 0) + 1;
    }

    /**
     * The nightly job: recompute the view from ALL data, from scratch.
     */
    public function runBatch(): void
    {
        // ---- BATCH LAYER: implementation #1 of the counting logic ----
        // It has the whole dataset in hand, so it can do things properly.
        $seen = [];
        $counts = [];

        foreach ($this->log->readFrom(0) as $event) {
            if (isset($seen[$event['id']])) {
                continue; // exact deduplication
            }

            $seen[$event['id']] = true;
            $counts[$event['page']] = ($counts[$event['page']] ?? 0) + 1;
        }

        $this->batchView = $counts;
        $this->batchOffset = $this->log->size();

        // The batch view now covers everything the speed layer was holding.
        $this->realtimeView = [];
        $this->batchRuns++;
    }

    /** SERVING LAYER: the answer is always batch view + realtime view. */
    public function views(string $page): int
    {
        return ($this->batchView[$page] ?? 0) + ($this->realtimeView[$page] ?? 0);
    }

    /** Events the batch layer has not processed yet (how much rests on the speed layer). */
    public function batchLag(): int
    {
        return $this->log->size() - $this->batchOffset;
    }
}
