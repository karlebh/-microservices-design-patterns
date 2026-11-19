<?php

declare(strict_types=1);

namespace Patterns\Resilience\DeadLetterQueue;

use Closure;
use Throwable;

/**
 * A FIFO queue with a dead letter queue attached.
 *
 * Each message carries a receive count. A failed message is retried from
 * the head of the queue (preserving order) until the count reaches
 * $maxReceiveCount, then it is moved aside so the rest can proceed.
 */
final class ResilientQueue
{
    /** @var list<array{id: string, payload: mixed, receiveCount: int}> */
    private array $messages = [];

    /** @var list<DeadLetter> */
    private array $deadLetters = [];

    private int $sequence = 0;

    /** @param int $maxReceiveCount attempts before a message is dead-lettered. PHP_INT_MAX = no DLQ at all */
    public function __construct(private readonly int $maxReceiveCount = 3) {}

    public function send(mixed $payload): string
    {
        $id = 'msg-'.(++$this->sequence);
        $this->messages[] = ['id' => $id, 'payload' => $payload, 'receiveCount' => 0];

        return $id;
    }

    /**
     * Hand the message at the head of the queue to $handler.
     *
     * @param  Closure(mixed): void  $handler
     * @return string|null `processed`, `retry`, `dead-lettered`, or null when the queue is empty
     */
    public function processNext(Closure $handler): ?string
    {
        if ($this->messages === []) {
            return null;
        }

        $attempt = ++$this->messages[0]['receiveCount'];
        $message = $this->messages[0];

        try {
            $handler($message['payload']);
        } catch (PermanentFailure $e) {
            // The handler knows retrying is pointless: skip straight to the DLQ.
            $this->deadLetter($message, $e, 'permanent failure');

            return 'dead-lettered';
        } catch (Throwable $e) {
            if ($attempt >= $this->maxReceiveCount) {
                $this->deadLetter($message, $e, 'retries exhausted');

                return 'dead-lettered';
            }

            return 'retry'; // stays at the head and will be delivered again
        }

        array_shift($this->messages);

        return 'processed';
    }

    /**
     * Keep processing until the queue is empty or $maxSteps deliveries were made.
     *
     * @return array<string, int> outcome => count
     */
    public function drain(Closure $handler, int $maxSteps = 1_000): array
    {
        $outcomes = ['processed' => 0, 'retry' => 0, 'dead-lettered' => 0];

        for ($step = 0; $step < $maxSteps && ($outcome = $this->processNext($handler)) !== null; $step++) {
            $outcomes[$outcome]++;
        }

        return $outcomes;
    }

    /** @return list<DeadLetter> */
    public function deadLetters(): array
    {
        return $this->deadLetters;
    }

    /**
     * Move dead letters back to the main queue, with their attempt counter
     * reset. Do this after the cause has been fixed.
     *
     * @return int how many messages were redriven
     */
    public function redrive(): int
    {
        $count = count($this->deadLetters);

        foreach ($this->deadLetters as $letter) {
            $this->messages[] = ['id' => $letter->id, 'payload' => $letter->payload, 'receiveCount' => 0];
        }

        $this->deadLetters = [];

        return $count;
    }

    public function size(): int
    {
        return count($this->messages);
    }

    private function deadLetter(array $message, Throwable $error, string $reason): void
    {
        array_shift($this->messages);

        $this->deadLetters[] = new DeadLetter(
            id: $message['id'],
            payload: $message['payload'],
            attempts: $message['receiveCount'],
            lastError: $error->getMessage(),
            reason: $reason,
        );
    }
}
