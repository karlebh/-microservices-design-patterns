<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\Gossip\GossipCluster;
use Patterns\Coordination\Gossip\GossipNode;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class GossipTest extends TestCase
{
    private function cluster(int $size, float $messageLoss = 0.0, int $seed = 42): GossipCluster
    {
        $cluster = new GossipCluster(new Randomizer(new Mt19937($seed)), fanout: 1, messageLoss: $messageLoss);
        $cluster->bootstrap($size);

        return $cluster;
    }

    /** Rounds until a rumour that starts on one node has reached every node. */
    private function roundsToSpread(GossipCluster $cluster): int
    {
        $cluster->node('node-1')->set('config', 'v2');

        return $cluster->roundsUntilAll(fn (GossipNode $node) => isset($node->data['config']));
    }

    // ---- Dissemination ------------------------------------------------------------------

    public function test_a_rumour_started_on_one_node_reaches_all_of_them(): void
    {
        $cluster = $this->cluster(32);

        $rounds = $this->roundsToSpread($cluster);

        $this->assertSame(32, $cluster->nodesKnowing('config'));
        $this->assertLessThanOrEqual(12, $rounds, '32 nodes, each talking to ONE random peer per round, and it still takes only a handful of rounds.');
    }

    public function test_the_number_of_informed_nodes_grows_exponentially(): void
    {
        $cluster = $this->cluster(128);
        $cluster->node('node-1')->set('config', 'v2');

        $informed = [];

        foreach (range(1, 5) as $_) {
            $cluster->round();
            $informed[] = $cluster->nodesKnowing('config');
        }

        $this->assertGreaterThanOrEqual(2 * $informed[1], $informed[3], 'Every informed node recruits others: the spread compounds.');
        $this->assertLessThan(128, $informed[0], 'Nobody broadcast to everyone.');
    }

    public function test_spreading_time_grows_with_the_logarithm_of_the_cluster_size(): void
    {
        $small = $this->roundsToSpread($this->cluster(16));
        $large = $this->roundsToSpread($this->cluster(256));

        $this->assertLessThan(3 * $small, $large, 'Sixteen times more nodes needs only a few more rounds, not sixteen times as many.');
    }

    public function test_gossip_survives_a_lossy_network(): void
    {
        $reliable = $this->roundsToSpread($this->cluster(64));
        $lossy = $this->roundsToSpread($this->cluster(64, messageLoss: 0.4));

        $this->assertLessThan(500, $lossy, 'With 40% of exchanges lost, everyone still finds out...');
        $this->assertGreaterThanOrEqual($reliable, $lossy, '...it just takes a little longer. No retries, no acknowledgements needed.');
    }

    public function test_the_newest_version_of_a_value_wins_everywhere(): void
    {
        $cluster = $this->cluster(16);

        $cluster->node('node-1')->set('feature-flag', 'off');
        $cluster->rounds(15);
        $cluster->node('node-9')->set('feature-flag', 'on'); // version 2, set on a different node
        $cluster->rounds(15);

        foreach (range(1, 16) as $i) {
            $this->assertSame('on', $cluster->node("node-{$i}")->data['feature-flag']['value']);
        }
    }

    public function test_each_node_does_a_constant_amount_of_work_per_round(): void
    {
        $cluster = $this->cluster(100);

        $cluster->rounds(10);

        $this->assertSame(100 * 10, $cluster->exchanges, 'N messages per round, not N squared.');
    }

    // ---- Membership ---------------------------------------------------------------------

    public function test_every_node_notices_a_crashed_member_without_any_coordinator(): void
    {
        $cluster = $this->cluster(16);
        $cluster->rounds(5);

        $cluster->crash('node-7');
        $cluster->rounds(40);

        foreach ([1, 2, 8, 16] as $i) {
            $view = $cluster->viewOf("node-{$i}");

            $this->assertNotContains('node-7', $view, "node-{$i} worked out that node-7 is gone.");
            $this->assertCount(15, $view);
        }
    }

    public function test_live_members_are_not_mistaken_for_dead(): void
    {
        $cluster = $this->cluster(16);

        $cluster->rounds(60);

        $this->assertCount(16, $cluster->viewOf('node-1'), 'Heartbeats keep arriving, by whatever route.');
    }

    public function test_a_new_node_becomes_known_to_everyone_through_a_single_seed(): void
    {
        $cluster = $this->cluster(16);
        $cluster->rounds(3);

        $cluster->join('node-17', seed: 'node-4');

        $this->assertNotContains('node-17', $cluster->viewOf('node-1'), 'At first only the seed can hear about it.');

        $cluster->rounds(20);

        $this->assertContains('node-17', $cluster->viewOf('node-1'));
        $this->assertCount(17, $cluster->viewOf('node-17'), 'And the newcomer has learned about every other member.');
    }

    public function test_failure_detection_also_works_when_messages_are_being_lost(): void
    {
        $cluster = $this->cluster(16, messageLoss: 0.3);
        $cluster->rounds(5);

        $cluster->crash('node-3');
        $cluster->rounds(60);

        $this->assertNotContains('node-3', $cluster->viewOf('node-10'));
        $this->assertCount(15, $cluster->viewOf('node-10'), 'No live node was wrongly declared dead because of the losses.');
    }
}
