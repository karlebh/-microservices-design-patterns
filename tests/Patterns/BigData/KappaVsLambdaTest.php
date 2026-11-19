<?php

declare(strict_types=1);

namespace Tests\Patterns\BigData;

use Closure;
use Patterns\BigData\KappaVsLambda\EventLog;
use Patterns\BigData\KappaVsLambda\KappaArchitecture;
use Patterns\BigData\KappaVsLambda\LambdaArchitecture;
use PHPUnit\Framework\TestCase;

final class KappaVsLambdaTest extends TestCase
{
    /** The single implementation used by Kappa: dedupe by event id, then count. */
    private function countDistinctViews(bool $ignoreBots = false): Closure
    {
        return function (array $state, array $event) use ($ignoreBots): array {
            if (isset($state['seen'][$event['id']]) || ($ignoreBots && $event['bot'])) {
                return $state;
            }

            $state['seen'][$event['id']] = true;
            $state['counts'][$event['page']] = ($state['counts'][$event['page']] ?? 0) + 1;

            return $state;
        };
    }

    // ---- Lambda -------------------------------------------------------------------------

    public function test_lambda_answers_from_the_speed_layer_until_the_batch_layer_catches_up(): void
    {
        $lambda = new LambdaArchitecture(new EventLog);

        $lambda->ingest(['id' => 'e1', 'page' => '/home']);
        $lambda->ingest(['id' => 'e2', 'page' => '/home']);

        $this->assertSame(2, $lambda->views('/home'), 'Fresh within moments of the events...');
        $this->assertSame(0, $lambda->batchRuns, '...although no batch job has run yet.');
        $this->assertSame(2, $lambda->batchLag());
    }

    public function test_lambda_merges_the_batch_view_with_the_realtime_view(): void
    {
        $lambda = new LambdaArchitecture(new EventLog);
        $lambda->ingest(['id' => 'e1', 'page' => '/home']);
        $lambda->ingest(['id' => 'e2', 'page' => '/home']);

        $lambda->runBatch();                                   // covers e1, e2
        $lambda->ingest(['id' => 'e3', 'page' => '/home']);    // only the speed layer knows this one

        $this->assertSame(3, $lambda->views('/home'), '2 from the batch view + 1 from the realtime view.');
        $this->assertSame(1, $lambda->batchLag());
    }

    public function test_lambda_batch_runs_correct_what_the_speed_layer_got_wrong(): void
    {
        $lambda = new LambdaArchitecture(new EventLog);

        $lambda->ingest(['id' => 'e1', 'page' => '/pricing']);
        $lambda->ingest(['id' => 'e1', 'page' => '/pricing']); // the tracker retried: same event twice
        $lambda->ingest(['id' => 'e2', 'page' => '/pricing']);

        $this->assertSame(3, $lambda->views('/pricing'), 'The speed layer counts the duplicate.');

        $lambda->runBatch();

        $this->assertSame(2, $lambda->views('/pricing'), 'The batch layer, with all data in hand, deduplicates and overrides it.');
    }

    // ---- Kappa --------------------------------------------------------------------------

    public function test_kappa_has_one_pipeline_for_everything(): void
    {
        $kappa = new KappaArchitecture(new EventLog, $this->countDistinctViews());

        $kappa->ingest(['id' => 'e1', 'page' => '/pricing']);
        $kappa->ingest(['id' => 'e1', 'page' => '/pricing']); // duplicate
        $kappa->ingest(['id' => 'e2', 'page' => '/pricing']);

        $this->assertSame(2, $kappa->views('/pricing'), 'Correct immediately: there is no second, sloppier code path to reconcile.');
    }

    public function test_kappa_reprocesses_by_replaying_the_log_through_a_new_version(): void
    {
        $kappa = new KappaArchitecture(new EventLog, $this->countDistinctViews());

        $kappa->ingest(['id' => 'e1', 'page' => '/home']);
        $kappa->ingest(['id' => 'e2', 'page' => '/home', 'bot' => true]);
        $kappa->ingest(['id' => 'e3', 'page' => '/home', 'bot' => true]);
        $kappa->ingest(['id' => 'e4', 'page' => '/home']);

        $this->assertSame(4, $kappa->views('/home'), 'v1 counted bot traffic: a bug we want to fix for ALL history.');

        // Deploy the corrected logic next to the old one. It starts from offset 0.
        $kappa->deploy('v2', $this->countDistinctViews(ignoreBots: true));
        $this->assertSame(4, $kappa->lag('v2'));

        $kappa->catchUp();

        $this->assertSame(0, $kappa->lag('v2'));
        $this->assertSame(4, $kappa->views('/home'), 'Readers are still served by v1 while v2 rebuilds.');
        $this->assertSame(2, $kappa->views('/home', version: 'v2'));

        $kappa->switchTo('v2');
        $kappa->retire('v1');

        $this->assertSame(2, $kappa->views('/home'), 'History has been recomputed with the new logic, with no batch system involved.');
    }

    public function test_kappa_new_versions_keep_up_with_live_traffic_after_the_replay(): void
    {
        $kappa = new KappaArchitecture(new EventLog, $this->countDistinctViews());
        $kappa->ingest(['id' => 'e1', 'page' => '/home']);

        $kappa->deploy('v2', $this->countDistinctViews(ignoreBots: true));
        $kappa->ingest(['id' => 'e2', 'page' => '/home']);
        $kappa->ingest(['id' => 'e3', 'page' => '/home', 'bot' => true]);

        $this->assertSame(3, $kappa->views('/home', version: 'v1'));
        $this->assertSame(2, $kappa->views('/home', version: 'v2'), 'Both versions consume the same log, old events and new.');
    }

    // ---- Side by side -------------------------------------------------------------------

    public function test_both_architectures_agree_once_lambda_has_run_its_batch(): void
    {
        $events = [
            ['id' => 'e1', 'page' => '/home'],
            ['id' => 'e2', 'page' => '/docs'],
            ['id' => 'e2', 'page' => '/docs'],
            ['id' => 'e3', 'page' => '/home'],
        ];

        $lambda = new LambdaArchitecture(new EventLog);
        $kappa = new KappaArchitecture(new EventLog, $this->countDistinctViews());

        foreach ($events as $event) {
            $lambda->ingest($event);
            $kappa->ingest($event);
        }

        $this->assertNotSame($kappa->views('/docs'), $lambda->views('/docs'), 'Before the batch run, Lambda\'s two code paths disagree with the truth.');

        $lambda->runBatch();

        $this->assertSame($kappa->views('/docs'), $lambda->views('/docs'));
        $this->assertSame($kappa->views('/home'), $lambda->views('/home'));
    }
}
