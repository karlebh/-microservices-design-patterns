<?php

declare(strict_types=1);

namespace Patterns\BigData\StreamProcessing;

/**
 * Counts events per key per window, in EVENT time.
 *
 * Events may arrive in any order. Each one is placed in the window(s) its
 * own timestamp belongs to. A window's result is emitted when the
 * WATERMARK — the operator's estimate of how far event time has
 * progressed — passes the end of that window.
 *
 *   watermark = highest event time seen so far − maxOutOfOrderness
 *
 * After that, an event for the window is "late":
 *   - within allowedLateness: the window is updated and re-emitted
 *   - beyond it:              the event goes to the side output
 */
final class WindowedCounter
{
    /** @var array<string, array{key: string, start: int, end: int, count: int, emitted: bool}> open window state */
    private array $windows = [];

    private int $maxEventTime = PHP_INT_MIN;

    /** @var list<array{key: string, start: int, end: int, count: int}> results, in the order they were emitted */
    public array $emitted = [];

    /** @var list<array{key: string, time: int}> events that arrived too late to be counted */
    public array $lateEvents = [];

    /**
     * @param  int  $maxOutOfOrderness  how far behind the newest event an event may lag and still be on time
     * @param  int  $allowedLateness  how long after a window was emitted it still accepts corrections
     */
    public function __construct(
        private readonly WindowAssigner $assigner,
        private readonly int $maxOutOfOrderness = 0,
        private readonly int $allowedLateness = 0,
    ) {}

    public function process(string $key, int $eventTime): void
    {
        foreach ($this->assigner->assign($eventTime) as $window) {
            // The window was already closed for good: this event missed it.
            if ($window['end'] + $this->allowedLateness <= $this->watermark()) {
                $this->lateEvents[] = ['key' => $key, 'time' => $eventTime];

                continue;
            }

            $id = "{$key}|{$window['start']}";
            $this->windows[$id] ??= ['key' => $key, 'start' => $window['start'], 'end' => $window['end'], 'count' => 0, 'emitted' => false];
            $this->windows[$id]['count']++;

            // A late-but-allowed event changes a result we already published: publish the correction.
            if ($this->windows[$id]['emitted']) {
                $this->emit($this->windows[$id]);
            }
        }

        $this->maxEventTime = max($this->maxEventTime, $eventTime);
        $this->advance();
    }

    /** "Probably everything up to here has arrived." */
    public function watermark(): int
    {
        return $this->maxEventTime === PHP_INT_MIN ? PHP_INT_MIN : $this->maxEventTime - $this->maxOutOfOrderness;
    }

    /** The stream ended (or a test wants everything out): emit all windows that are still open. */
    public function flush(): void
    {
        $this->maxEventTime = PHP_INT_MAX - $this->maxOutOfOrderness;
        $this->advance();
    }

    /** @return array<string, int> "key@start" => count, taking the latest emission of each window */
    public function results(): array
    {
        $results = [];

        foreach ($this->emitted as $result) {
            $results["{$result['key']}@{$result['start']}"] = $result['count'];
        }

        ksort($results, SORT_NATURAL);

        return $results;
    }

    /** Emit windows the watermark has passed, and forget those that can no longer change. */
    private function advance(): void
    {
        $watermark = $this->watermark();

        // Oldest windows first, so results come out in time order.
        uasort($this->windows, fn (array $a, array $b) => [$a['start'], $a['key']] <=> [$b['start'], $b['key']]);

        foreach ($this->windows as $id => $window) {
            if (! $window['emitted'] && $window['end'] <= $watermark) {
                $this->emit($window);
                $this->windows[$id]['emitted'] = true;
            }

            if ($window['end'] + $this->allowedLateness <= $watermark) {
                unset($this->windows[$id]); // state can be dropped: nothing may touch it any more
            }
        }
    }

    private function emit(array $window): void
    {
        $this->emitted[] = ['key' => $window['key'], 'start' => $window['start'], 'end' => $window['end'], 'count' => $window['count']];
    }
}
