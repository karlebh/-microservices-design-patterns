<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Patterns\Resilience\DeadLetterQueue\PermanentFailure;
use Patterns\Resilience\DeadLetterQueue\ResilientQueue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeadLetterQueueTest extends TestCase
{
    /** A handler that chokes on one specific payload and records everything else. */
    private function handler(array &$processed): \Closure
    {
        return function (string $payload) use (&$processed) {
            if ($payload === 'POISON') {
                throw new RuntimeException('cannot parse payload');
            }

            $processed[] = $payload;
        };
    }

    public function test_without_a_dead_letter_queue_a_poison_pill_blocks_the_queue_forever(): void
    {
        $queue = new ResilientQueue(maxReceiveCount: PHP_INT_MAX);
        $processed = [];

        $queue->send('order 1');
        $queue->send('POISON');
        $queue->send('order 2');

        $outcomes = $queue->drain($this->handler($processed), maxSteps: 50);

        $this->assertSame(['order 1'], $processed, 'order 2 is healthy but can never be reached.');
        $this->assertSame(49, $outcomes['retry'], 'Fifty deliveries later, the consumer is still retrying the same message.');
    }

    public function test_a_poison_pill_is_moved_aside_after_the_maximum_number_of_attempts(): void
    {
        $queue = new ResilientQueue(maxReceiveCount: 3);
        $processed = [];

        $queue->send('order 1');
        $queue->send('POISON');
        $queue->send('order 2');

        $outcomes = $queue->drain($this->handler($processed));

        $this->assertSame(['order 1', 'order 2'], $processed, 'The queue keeps flowing.');
        $this->assertSame(['processed' => 2, 'retry' => 2, 'dead-lettered' => 1], $outcomes);
        $this->assertSame(0, $queue->size());
    }

    public function test_a_dead_letter_keeps_the_evidence_needed_to_diagnose_it(): void
    {
        $queue = new ResilientQueue(maxReceiveCount: 3);
        $processed = [];
        $queue->send('POISON');
        $queue->drain($this->handler($processed));

        [$letter] = $queue->deadLetters();

        $this->assertSame('POISON', $letter->payload);
        $this->assertSame(3, $letter->attempts);
        $this->assertSame('cannot parse payload', $letter->lastError);
        $this->assertSame('retries exhausted', $letter->reason);
    }

    public function test_a_transient_failure_is_retried_and_never_reaches_the_dead_letter_queue(): void
    {
        $queue = new ResilientQueue(maxReceiveCount: 3);
        $queue->send('charge card');
        $failuresLeft = 2;

        $outcomes = $queue->drain(function () use (&$failuresLeft) {
            if ($failuresLeft-- > 0) {
                throw new RuntimeException('payment gateway timeout');
            }
        });

        $this->assertSame(['processed' => 1, 'retry' => 2, 'dead-lettered' => 0], $outcomes);
        $this->assertSame([], $queue->deadLetters());
    }

    public function test_a_permanent_failure_skips_the_retries(): void
    {
        $queue = new ResilientQueue(maxReceiveCount: 5);
        $queue->send('{"amount": -5}');

        $outcomes = $queue->drain(fn () => throw new PermanentFailure('amount must be positive'));

        $this->assertSame(0, $outcomes['retry'], 'No point trying an invalid message five times.');
        $this->assertSame(1, $queue->deadLetters()[0]->attempts);
        $this->assertSame('permanent failure', $queue->deadLetters()[0]->reason);
    }

    public function test_dead_letters_are_redriven_once_the_cause_is_fixed(): void
    {
        $queue = new ResilientQueue(maxReceiveCount: 2);
        $processed = [];
        $bugIsFixed = false;

        $handler = function (string $payload) use (&$processed, &$bugIsFixed) {
            if (! $bugIsFixed) {
                throw new RuntimeException('bug in the consumer');
            }

            $processed[] = $payload;
        };

        $queue->send('order 1');
        $queue->send('order 2');
        $queue->drain($handler);

        $this->assertCount(2, $queue->deadLetters(), 'Both failed: the fault was in the consumer, not the messages.');

        // A fix is deployed, then the parked messages are replayed.
        $bugIsFixed = true;
        $this->assertSame(2, $queue->redrive());
        $queue->drain($handler);

        $this->assertSame(['order 1', 'order 2'], $processed, 'Nothing was lost.');
        $this->assertSame([], $queue->deadLetters());
    }
}
