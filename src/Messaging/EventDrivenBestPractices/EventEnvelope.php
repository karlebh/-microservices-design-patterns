<?php

declare(strict_types=1);

namespace Patterns\Messaging\EventDrivenBestPractices;

/**
 * A standard wrapper around every event (the same idea as CloudEvents).
 *
 * Everything a broker, a tracer, a deduplicator or an on-call engineer needs
 * is in the envelope, so none of them has to understand the payload.
 */
final readonly class EventEnvelope
{
    public function __construct(
        /** Globally unique. Consumers dedupe on it. */
        public string $id,
        /** A fact in the past tense: `order.placed`, not `place.order`. */
        public string $type,
        /** Schema version of `data`. */
        public int $version,
        /** The service that published it. */
        public string $source,
        /** When it happened (ISO-8601, UTC), not when it was sent or received. */
        public string $occurredAt,
        /** Shared by every event in the same business flow. */
        public string $correlationId,
        /** The id of the event that directly triggered this one; null for the first of a flow. */
        public ?string $causationId,
        public array $data,
    ) {}

    /** The first event of a new flow: it is its own correlation root. */
    public static function start(string $type, string $source, array $data, int $version = 1): self
    {
        $id = self::newId();

        return new self($id, $type, $version, $source, gmdate('Y-m-d\TH:i:s\Z'), $id, null, $data);
    }

    /**
     * An event published BECAUSE of this one. The correlation id is carried
     * over unchanged; the causation id points back at this event.
     */
    public function causes(string $type, string $source, array $data, int $version = 1): self
    {
        return new self(self::newId(), $type, $version, $source, gmdate('Y-m-d\TH:i:s\Z'), $this->correlationId, $this->id, $data);
    }

    public function withData(array $data, int $version): self
    {
        return new self($this->id, $this->type, $version, $this->source, $this->occurredAt, $this->correlationId, $this->causationId, $data);
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
