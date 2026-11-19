<?php

declare(strict_types=1);

namespace Tests\Patterns\BigData;

use Patterns\BigData\StreamProcessing\SessionWindows;
use Patterns\BigData\StreamProcessing\SlidingWindows;
use Patterns\BigData\StreamProcessing\TumblingWindows;
use Patterns\BigData\StreamProcessing\WindowedCounter;
use PHPUnit\Framework\TestCase;

final class StreamProcessingTest extends TestCase
{
    // ---- Window shapes ------------------------------------------------------------------

    public function test_tumbling_windows_put_each_event_in_exactly_one_window(): void
    {
        $windows = new TumblingWindows(size: 60);

        $this->assertSame([['start' => 0, 'end' => 60]], $windows->assign(0));
        $this->assertSame([['start' => 0, 'end' => 60]], $windows->assign(59));
        $this->assertSame([['start' => 60, 'end' => 120]], $windows->assign(60), 'The end is exclusive.');
    }

    public function test_sliding_windows_overlap_so_an_event_is_in_several(): void
    {
        $windows = new SlidingWindows(size: 60, slide: 30);

        $this->assertSame(
            [['start' => 30, 'end' => 90], ['start' => 60, 'end' => 120]],
            $windows->assign(70),
            'Second 70 is in "30-90" and in "60-120".'
        );
        $this->assertCount(1, $windows->assign(10), 'Only one window exists that early.');
    }

    public function test_session_windows_follow_bursts_of_activity(): void
    {
        $sessions = new SessionWindows(gap: 30);

        foreach ([0, 10, 25, 200, 215] as $time) {
            $sessions->process('ada', $time);
        }

        $this->assertSame([
            ['start' => 0, 'end' => 55, 'count' => 3],     // three clicks close together
            ['start' => 200, 'end' => 245, 'count' => 2],  // came back later: a new visit
        ], $sessions->sessionsOf('ada'));
    }

    public function test_an_out_of_order_event_can_merge_two_sessions(): void
    {
        $sessions = new SessionWindows(gap: 30);
        $sessions->process('ada', 0);
        $sessions->process('ada', 50);

        $this->assertCount(2, $sessions->sessionsOf('ada'), 'Fifty seconds apart: two visits, as far as we know.');

        $sessions->process('ada', 25); // arrives late, and sits right between them

        $this->assertSame([['start' => 0, 'end' => 80, 'count' => 3]], $sessions->sessionsOf('ada'), 'It was one visit after all.');
    }

    public function test_sessions_are_tracked_per_key(): void
    {
        $sessions = new SessionWindows(gap: 30);
        $sessions->process('ada', 0);
        $sessions->process('bob', 10);

        $this->assertCount(1, $sessions->sessionsOf('ada'));
        $this->assertSame(1, $sessions->sessionsOf('bob')[0]['count']);
    }

    // ---- Event time ---------------------------------------------------------------------

    public function test_events_are_counted_in_the_window_they_happened_in_not_the_one_they_arrived_in(): void
    {
        $counter = new WindowedCounter(new TumblingWindows(60), maxOutOfOrderness: 30);

        // Arrival order differs from the order of the timestamps.
        foreach ([5, 61, 40, 20, 70] as $eventTime) {
            $counter->process('page-views', $eventTime);
        }

        $counter->flush();

        $this->assertSame(['page-views@0' => 3, 'page-views@60' => 2], $counter->results());
    }

    public function test_counting_by_arrival_time_would_give_a_different_wrong_answer(): void
    {
        // The same five events, each tagged with when it ARRIVED. The ones from
        // seconds 40 and 20 were delayed and showed up during the second minute.
        $events = [['happened' => 5, 'arrived' => 6], ['happened' => 61, 'arrived' => 62], ['happened' => 40, 'arrived' => 65], ['happened' => 20, 'arrived' => 66], ['happened' => 70, 'arrived' => 71]];

        $byProcessingTime = new WindowedCounter(new TumblingWindows(60));
        $byEventTime = new WindowedCounter(new TumblingWindows(60), maxOutOfOrderness: 60);

        foreach ($events as $event) {
            $byProcessingTime->process('page-views', $event['arrived']);
            $byEventTime->process('page-views', $event['happened']);
        }

        $byProcessingTime->flush();
        $byEventTime->flush();

        $this->assertSame(['page-views@0' => 1, 'page-views@60' => 4], $byProcessingTime->results(), 'A network delay rewrote history.');
        $this->assertSame(['page-views@0' => 3, 'page-views@60' => 2], $byEventTime->results(), 'What actually happened.');
    }

    public function test_keys_are_aggregated_independently(): void
    {
        $counter = new WindowedCounter(new TumblingWindows(60));

        $counter->process('/home', 10);
        $counter->process('/docs', 20);
        $counter->process('/home', 30);
        $counter->flush();

        $this->assertSame(['/docs@0' => 1, '/home@0' => 2], $counter->results());
    }

    // ---- Watermarks and late data -------------------------------------------------------

    public function test_a_window_is_emitted_only_when_the_watermark_passes_its_end(): void
    {
        $counter = new WindowedCounter(new TumblingWindows(60), maxOutOfOrderness: 10);

        $counter->process('k', 30);
        $counter->process('k', 65);

        $this->assertSame(55, $counter->watermark(), 'Newest event (65) minus the tolerated disorder (10).');
        $this->assertSame([], $counter->emitted, 'Window [0, 60) is not final yet: an event from second 58 could still be on its way.');

        $counter->process('k', 58); // and here it is, on time
        $counter->process('k', 71); // watermark moves to 61

        $this->assertSame([['key' => 'k', 'start' => 0, 'end' => 60, 'count' => 2]], $counter->emitted);
    }

    public function test_the_watermark_trades_latency_for_completeness(): void
    {
        $impatient = new WindowedCounter(new TumblingWindows(60), maxOutOfOrderness: 0);
        $patient = new WindowedCounter(new TumblingWindows(60), maxOutOfOrderness: 30);

        foreach ([10, 62, 50] as $eventTime) { // 50 is a straggler
            $impatient->process('k', $eventTime);
            $patient->process('k', $eventTime);
        }

        $impatient->flush();
        $patient->flush();

        $this->assertSame(1, $impatient->results()['k@0'], 'Emitted early, and wrong: the straggler was dropped.');
        $this->assertCount(1, $impatient->lateEvents);

        $this->assertSame(2, $patient->results()['k@0'], 'Waited 30 seconds longer and got it right.');
    }

    public function test_allowed_lateness_lets_a_published_result_be_corrected(): void
    {
        $counter = new WindowedCounter(new TumblingWindows(60), maxOutOfOrderness: 0, allowedLateness: 120);

        $counter->process('k', 10);
        $counter->process('k', 65);   // watermark 65: [0, 60) is emitted with count 1
        $counter->process('k', 20);   // late, but within the allowed lateness

        $this->assertSame(
            [1, 2],
            array_column(array_filter($counter->emitted, fn (array $r) => $r['start'] === 0), 'count'),
            'First the on-time result, then an updated one. Downstream must handle revisions.'
        );
        $this->assertSame([], $counter->lateEvents);
    }

    public function test_events_beyond_the_allowed_lateness_go_to_a_side_output(): void
    {
        $counter = new WindowedCounter(new TumblingWindows(60), maxOutOfOrderness: 0, allowedLateness: 30);

        $counter->process('k', 10);
        $counter->process('k', 200);  // the watermark is now far past window [0, 60)
        $counter->process('k', 15);   // hopelessly late

        $counter->flush();

        $this->assertSame(1, $counter->results()['k@0'], 'The closed window is not reopened...');
        $this->assertSame([['key' => 'k', 'time' => 15]], $counter->lateEvents, '...but the event is captured, not lost.');
    }

    public function test_a_sliding_window_count_is_a_moving_total(): void
    {
        $counter = new WindowedCounter(new SlidingWindows(size: 60, slide: 30));

        foreach ([10, 40, 70, 100] as $eventTime) {
            $counter->process('k', $eventTime);
        }

        $counter->flush();

        $this->assertSame(['k@0' => 2, 'k@30' => 2, 'k@60' => 2, 'k@90' => 1], $counter->results());
    }
}
