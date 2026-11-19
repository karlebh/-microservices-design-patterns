<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Patterns\Resilience\Bulkhead\Bulkhead;
use Patterns\Resilience\Bulkhead\BulkheadFullException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BulkheadTest extends TestCase
{
    /** Start $count calls that never finish, as if the dependency had hung. */
    private function hang(Bulkhead $bulkhead, int $count): int
    {
        $started = 0;

        for ($i = 0; $i < $count; $i++) {
            try {
                $bulkhead->acquire();
                $started++;
            } catch (BulkheadFullException) {
                // turned away
            }
        }

        return $started;
    }

    public function test_without_bulkheads_one_slow_dependency_starves_everything_else(): void
    {
        // One pool of 10 workers shared by every dependency.
        $sharedPool = new Bulkhead('shared-worker-pool', maxConcurrent: 10);

        // Recommendations hangs, and 20 requests that use it arrive.
        $this->hang($sharedPool, 20);

        $this->assertSame(0, $sharedPool->available());

        $this->expectException(BulkheadFullException::class);
        $sharedPool->execute(fn () => 'charge the card'); // payments is perfectly healthy, but there is no worker left
    }

    public function test_with_bulkheads_the_damage_is_contained(): void
    {
        $recommendations = new Bulkhead('recommendations', maxConcurrent: 3);
        $payments = new Bulkhead('payments', maxConcurrent: 5);

        $started = $this->hang($recommendations, 20);

        $this->assertSame(3, $started, 'Only three workers are stuck on the broken dependency...');
        $this->assertSame(17, $recommendations->rejected, '...the other 17 requests were told "no" immediately.');

        $this->assertSame('charged', $payments->execute(fn () => 'charged'), 'Payments does not even notice.');
    }

    public function test_a_slot_is_returned_when_the_call_finishes(): void
    {
        $bulkhead = new Bulkhead('inventory', maxConcurrent: 1);

        $bulkhead->execute(fn () => 'first');

        $this->assertSame(1, $bulkhead->available());
        $this->assertSame('second', $bulkhead->execute(fn () => 'second'));
    }

    public function test_a_slot_is_returned_even_when_the_call_fails(): void
    {
        $bulkhead = new Bulkhead('inventory', maxConcurrent: 1);

        try {
            $bulkhead->execute(fn () => throw new RuntimeException('dependency error'));
        } catch (RuntimeException) {
            // the failure itself is not the bulkhead's concern
        }

        $this->assertSame(1, $bulkhead->available(), 'A leaked slot would shrink the compartment forever.');
    }

    public function test_a_bounded_queue_absorbs_a_short_burst_and_then_rejects(): void
    {
        $bulkhead = new Bulkhead('reports', maxConcurrent: 2, maxWaiting: 2);

        $this->assertTrue($bulkhead->acquire());   // running
        $this->assertTrue($bulkhead->acquire());   // running
        $this->assertFalse($bulkhead->acquire());  // waiting
        $this->assertFalse($bulkhead->acquire());  // waiting

        try {
            $bulkhead->acquire();
            $this->fail('The fifth call should not be allowed to pile up.');
        } catch (BulkheadFullException) {
            $this->assertSame(1, $bulkhead->rejected);
        }

        // A running call finishes: a waiting call takes its slot.
        $bulkhead->release();

        $this->assertSame(2, $bulkhead->inFlight());
        $this->assertSame(1, $bulkhead->waiting());
    }
}
