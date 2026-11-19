<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DeliverySemantics;

/**
 * One consumer, three delivery guarantees.
 *
 * Every strategy does the same two things — apply the message, record that
 * it was handled. They differ only in the ORDER of those two things and in
 * whether they are atomic. Compare the three private methods below.
 */
final class Consumer
{
    public function __construct(
        private readonly MessageLog $log,
        private readonly Ledger $ledger,
        private readonly DeliveryGuarantee $guarantee,
        private readonly string $name = 'ledger-consumer',
    ) {}

    /**
     * Consume everything currently available.
     *
     * @param  int|null  $crashAtOffset  simulate the process dying while handling this message,
     *                                   at the worst possible moment for the chosen guarantee
     *
     * @throws ProcessCrashed
     */
    public function run(?int $crashAtOffset = null): void
    {
        while (($message = $this->log->messageAt($this->position())) !== null) {
            $crash = $message['offset'] === $crashAtOffset;

            match ($this->guarantee) {
                DeliveryGuarantee::AtMostOnce => $this->acknowledgeThenProcess($message, $crash),
                DeliveryGuarantee::AtLeastOnce => $this->processThenAcknowledge($message, $crash),
                DeliveryGuarantee::ExactlyOnce => $this->processAndAcknowledgeAtomically($message, $crash),
            };
        }
    }

    /** AT-MOST-ONCE: a crash after the ack means the message is gone for good. */
    private function acknowledgeThenProcess(array $message, bool $crash): void
    {
        $this->log->commit($this->name, $message['offset'] + 1);

        $this->crashIf($crash);

        $this->ledger->apply($message['id'], $message['amount']);
    }

    /** AT-LEAST-ONCE: a crash before the ack means the message comes back and is applied again. */
    private function processThenAcknowledge(array $message, bool $crash): void
    {
        $this->ledger->apply($message['id'], $message['amount']);

        $this->crashIf($crash);

        $this->log->commit($this->name, $message['offset'] + 1);
    }

    /**
     * EXACTLY-ONCE: effect and position are one transaction, so there is no
     * "between" to crash in. A crash can only happen before (nothing stored,
     * the message is retried) or after (everything stored, it is not).
     */
    private function processAndAcknowledgeAtomically(array $message, bool $crash): void
    {
        $this->crashIf($crash);

        $this->ledger->applyAndAdvance($message['id'], $message['amount'], $message['offset'] + 1);
    }

    /** Where to resume from after a restart. */
    private function position(): int
    {
        // Exactly-once trusts the position stored WITH the data, not the broker's.
        return $this->guarantee === DeliveryGuarantee::ExactlyOnce
            ? $this->ledger->nextOffset()
            : $this->log->committedOffset($this->name);
    }

    private function crashIf(bool $crash): void
    {
        if ($crash) {
            throw new ProcessCrashed('The consumer process died.');
        }
    }
}
