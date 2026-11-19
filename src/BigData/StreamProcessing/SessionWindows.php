<?php

declare(strict_types=1);

namespace Patterns\BigData\StreamProcessing;

/**
 * Session windows: bursts of activity separated by inactivity.
 *
 * There is no fixed size. A session for a key starts with an event and
 * keeps extending while further events arrive within `gap` of it. Because
 * events can arrive out of order, a new event may also land BETWEEN two
 * existing sessions and bridge them, in which case they merge into one.
 */
final class SessionWindows
{
    /** @var array<string, list<array{start: int, end: int, count: int}>> key => its sessions, sorted by start */
    private array $sessions = [];

    public function __construct(private readonly int $gap) {}

    public function process(string $key, int $eventTime): void
    {
        // Every event starts as its own tiny session [time, time + gap)...
        $sessions = [...$this->sessions[$key] ?? [], ['start' => $eventTime, 'end' => $eventTime + $this->gap, 'count' => 1]];

        usort($sessions, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        // ...and sessions that touch or overlap are merged.
        $merged = [];

        foreach ($sessions as $session) {
            $last = array_key_last($merged);

            if ($last !== null && $session['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $session['end']);
                $merged[$last]['count'] += $session['count'];
            } else {
                $merged[] = $session;
            }
        }

        $this->sessions[$key] = $merged;
    }

    /**
     * @return list<array{start: int, end: int, count: int}> `end` is the last event time plus the gap
     */
    public function sessionsOf(string $key): array
    {
        return $this->sessions[$key] ?? [];
    }
}
