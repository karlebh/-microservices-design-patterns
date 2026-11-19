<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use Patterns\DataConsistency\CompensatingTransactions\CompensationScope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompensatingTransactionsTest extends TestCase
{
    public function test_completed_steps_are_undone_in_reverse_order_when_a_later_step_fails(): void
    {
        $scope = new CompensationScope;
        $bookings = [];

        try {
            $scope->run('flight', function () use (&$bookings) {
                $bookings['flight'] = 'FL-1';

                return 'FL-1';
            }, function (string $ref) use (&$bookings) {
                unset($bookings['flight']);
            });

            $scope->run('hotel', function () use (&$bookings) {
                $bookings['hotel'] = 'HT-9';

                return 'HT-9';
            }, function (string $ref) use (&$bookings) {
                unset($bookings['hotel']);
            });

            $scope->run('car', fn () => throw new RuntimeException('no cars available'), fn () => null);
        } catch (RuntimeException) {
            $scope->compensate();
        }

        $this->assertSame([], $bookings, 'The trip is fully cancelled.');
        $this->assertSame(
            ['done: flight', 'done: hotel', 'compensated: hotel', 'compensated: flight'],
            $scope->journal,
            'Newest first; the failed step itself needs no undo.'
        );
    }

    public function test_a_compensation_is_a_new_action_not_an_erasure(): void
    {
        $scope = new CompensationScope;
        $ledger = [];

        $scope->run(
            'charge',
            function () use (&$ledger) {
                $ledger[] = ['charge', 100];

                return 100;
            },
            function (int $amount) use (&$ledger) {
                $ledger[] = ['refund', -$amount];
            },
        );

        $scope->compensate();

        $this->assertSame([['charge', 100], ['refund', -100]], $ledger, 'Both entries exist: history is kept.');
        $this->assertSame(0, array_sum(array_column($ledger, 1)), 'The net effect is zero.');
    }

    public function test_the_undo_receives_what_the_step_produced(): void
    {
        $scope = new CompensationScope;
        $cancelled = null;

        $ref = $scope->run('flight', fn () => 'FL-42', function (string $bookingRef) use (&$cancelled) {
            $cancelled = $bookingRef;
        });

        $scope->compensate();

        $this->assertSame('FL-42', $ref);
        $this->assertSame('FL-42', $cancelled, 'The compensation knows exactly which booking to cancel.');
    }

    public function test_a_flaky_compensation_is_retried_until_it_succeeds(): void
    {
        $scope = new CompensationScope(maxCompensationAttempts: 3);
        $attempts = 0;

        $scope->run('hotel', fn () => 'HT-9', function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new RuntimeException('hotel API timeout');
            }
        });

        $scope->compensate();

        $this->assertSame(3, $attempts);
        $this->assertSame([], $scope->needsManualIntervention);
        $this->assertSame('compensated: hotel', end($scope->journal));
    }

    public function test_a_compensation_that_never_works_is_escalated_while_the_rest_still_runs(): void
    {
        $scope = new CompensationScope(maxCompensationAttempts: 2);
        $flightCancelled = false;

        $scope->run('flight', fn () => 'FL-1', function () use (&$flightCancelled) {
            $flightCancelled = true;
        });
        $scope->run('hotel', fn () => 'HT-9', fn () => throw new RuntimeException('hotel system is down'));

        $scope->compensate(); // does not throw

        $this->assertSame(['hotel'], $scope->needsManualIntervention, 'Someone has to cancel the hotel by hand.');
        $this->assertTrue($flightCancelled, 'One stuck step does not stop the others from being undone.');
    }

    public function test_compensating_twice_does_nothing_the_second_time(): void
    {
        $scope = new CompensationScope;
        $refunds = 0;

        $scope->run('charge', fn () => 100, function () use (&$refunds) {
            $refunds++;
        });

        $scope->compensate();
        $scope->compensate();

        $this->assertSame(1, $refunds);
    }
}
