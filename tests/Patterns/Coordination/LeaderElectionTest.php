<?php

declare(strict_types=1);

namespace Tests\Patterns\Coordination;

use Patterns\Coordination\LeaderElection\BullyElection;
use Patterns\Coordination\LeaderElection\RaftElection;
use Patterns\Coordination\LeaderElection\ZooKeeperElection;
use PHPUnit\Framework\TestCase;

final class LeaderElectionTest extends TestCase
{
    // ==== Bully ==========================================================================

    public function test_bully_the_highest_live_node_wins(): void
    {
        $cluster = new BullyElection([1, 2, 3, 4, 5]);
        $this->assertSame(5, $cluster->leader());

        $cluster->crash(5);

        // Node 2 is the first to notice the leader is gone.
        $this->assertSame(4, $cluster->startElection(2));
        $this->assertSame(4, $cluster->leader());
    }

    public function test_bully_a_lower_node_is_told_to_stand_down_by_a_higher_one(): void
    {
        $cluster = new BullyElection([1, 2, 3]);
        $cluster->crash(3);

        $cluster->startElection(1);

        $this->assertSame([
            '1 sends ELECTION to all higher nodes',
            '1 got an OK and stands down',
            '2 sends ELECTION to all higher nodes',
            '2 hears no answer and announces itself as COORDINATOR',
        ], $cluster->trace);
    }

    public function test_bully_skips_over_several_dead_nodes(): void
    {
        $cluster = new BullyElection([1, 2, 3, 4, 5]);
        $cluster->crash(5);
        $cluster->crash(4);
        $cluster->crash(3);

        $this->assertSame(2, $cluster->startElection(1));
    }

    public function test_bully_a_recovered_higher_node_takes_leadership_back(): void
    {
        $cluster = new BullyElection([1, 2, 3]);
        $cluster->crash(3);
        $cluster->startElection(1);
        $this->assertSame(2, $cluster->leader());

        $this->assertSame(3, $cluster->recover(3), 'Hence the name: the biggest node bullies its way in.');
    }

    public function test_bully_uses_more_messages_when_a_low_node_starts_the_election(): void
    {
        $fromTheBottom = new BullyElection(range(1, 8));
        $fromTheBottom->crash(8);
        $fromTheBottom->startElection(1);

        $fromTheTop = new BullyElection(range(1, 8));
        $fromTheTop->crash(8);
        $fromTheTop->startElection(7);

        $this->assertGreaterThan(3 * $fromTheTop->messages, $fromTheBottom->messages, 'Worst case is quadratic: every node above the initiator runs its own election.');
    }

    // ==== Raft-style =====================================================================

    public function test_raft_a_candidate_becomes_leader_with_a_majority_of_votes(): void
    {
        $cluster = new RaftElection(['a', 'b', 'c', 'd', 'e']);

        $this->assertTrue($cluster->campaign('b'));

        $this->assertSame('leader', $cluster->role('b'));
        $this->assertSame([1 => ['b']], $cluster->leadersByTerm());
        $this->assertSame(1, $cluster->term('e'), 'Everyone has moved to the new term.');
    }

    public function test_raft_a_node_votes_only_once_per_term_so_a_term_has_at_most_one_leader(): void
    {
        $cluster = new RaftElection(['a', 'b', 'c', 'd']);

        // The network briefly splits so that a and b each reach one other node,
        // and both time out at the same moment: two candidates in term 1.
        $cluster->partition(['a', 'c'], ['b', 'd']);

        $this->assertFalse($cluster->campaign('a'), '2 votes of 4 is not a majority.');
        $this->assertFalse($cluster->campaign('b'));
        $this->assertSame([], $cluster->leadersByTerm(), 'A split vote: term 1 ends with NO leader, which is safe.');

        // Randomised timeouts mean one candidate retries first, in a new term.
        $cluster->heal();

        $this->assertTrue($cluster->campaign('a'));
        $this->assertSame([2 => ['a']], $cluster->leadersByTerm());
    }

    public function test_raft_the_minority_side_of_a_partition_cannot_elect_a_leader(): void
    {
        $cluster = new RaftElection(['a', 'b', 'c', 'd', 'e']);
        $cluster->partition(['a', 'b'], ['c', 'd', 'e']);

        $this->assertFalse($cluster->campaign('a'), 'Two nodes can never gather three votes.');
        $this->assertTrue($cluster->campaign('c'), 'The majority side elects one.');

        $this->assertSame('candidate', $cluster->role('a'));
        $this->assertCount(1, array_merge(...array_values($cluster->leadersByTerm())), 'One leader in the whole cluster: no split brain.');
    }

    public function test_raft_an_old_leader_steps_down_when_it_meets_a_higher_term(): void
    {
        $cluster = new RaftElection(['a', 'b', 'c', 'd', 'e']);
        $cluster->campaign('a'); // leader of term 1

        // 'a' is cut off. The others elect a replacement.
        $cluster->partition(['a'], ['b', 'c', 'd', 'e']);
        $cluster->campaign('b');

        $this->assertSame([1 => ['a'], 2 => ['b']], $cluster->leadersByTerm(), 'Two nodes believe they lead — but in DIFFERENT terms, and only one has a majority behind it.');

        $cluster->heal();
        $cluster->heartbeat('b');

        $this->assertSame('follower', $cluster->role('a'), 'Term 2 beats term 1: the stale leader yields.');
        $this->assertSame([2 => ['b']], $cluster->leadersByTerm());
    }

    public function test_raft_a_stale_leaders_heartbeat_is_ignored(): void
    {
        $cluster = new RaftElection(['a', 'b', 'c']);
        $cluster->campaign('a');
        $cluster->partition(['a'], ['b', 'c']);
        $cluster->campaign('b');
        $cluster->heal();

        $cluster->heartbeat('a'); // 'a' has not heard the news yet and still tries to lead

        $this->assertSame('leader', $cluster->role('b'), 'A message from an older term changes nothing.');
        $this->assertSame(2, $cluster->term('c'));
    }

    // ==== ZooKeeper recipe ===============================================================

    public function test_zookeeper_the_candidate_with_the_lowest_sequence_number_leads(): void
    {
        $election = new ZooKeeperElection;

        $this->assertSame([0, 1, 2], [$election->join('web-1'), $election->join('web-2'), $election->join('web-3')]);
        $this->assertSame('web-1', $election->leader());
    }

    public function test_zookeeper_the_next_in_line_takes_over_when_the_leaders_session_ends(): void
    {
        $election = new ZooKeeperElection;
        $election->join('web-1');
        $election->join('web-2');
        $election->join('web-3');

        $election->sessionExpired('web-1'); // its ephemeral node disappears by itself

        $this->assertSame('web-2', $election->leader());
    }

    public function test_zookeeper_each_candidate_watches_only_its_predecessor(): void
    {
        $election = new ZooKeeperElection;

        foreach (['web-1', 'web-2', 'web-3', 'web-4'] as $candidate) {
            $election->join($candidate);
        }

        $this->assertNull($election->watchedBy('web-1'), 'The leader watches nobody.');
        $this->assertSame('web-1', $election->watchedBy('web-2'));
        $this->assertSame('web-3', $election->watchedBy('web-4'));

        $election->sessionExpired('web-1');

        $this->assertSame(['web-2'], $election->notified, 'One node is woken, not three: no herd effect.');
    }

    public function test_zookeeper_losing_a_candidate_in_the_middle_does_not_disturb_the_leader(): void
    {
        $election = new ZooKeeperElection;

        foreach (['web-1', 'web-2', 'web-3'] as $candidate) {
            $election->join($candidate);
        }

        $election->sessionExpired('web-2');

        $this->assertSame('web-1', $election->leader());
        $this->assertSame(['web-3'], $election->notified);
        $this->assertSame('web-1', $election->watchedBy('web-3'), 'web-3 simply watches its new predecessor.');
    }

    public function test_zookeeper_a_returning_node_joins_at_the_back_of_the_queue(): void
    {
        $election = new ZooKeeperElection;
        $election->join('web-1');
        $election->join('web-2');

        $election->sessionExpired('web-1');
        $election->join('web-1'); // back again, with a new, higher sequence number

        $this->assertSame(['web-2', 'web-1'], $election->queue());
        $this->assertSame('web-2', $election->leader(), 'Unlike Bully, a recovered node does not seize leadership: no needless disruption.');
    }
}
