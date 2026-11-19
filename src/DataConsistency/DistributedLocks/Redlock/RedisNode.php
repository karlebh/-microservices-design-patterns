<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DistributedLocks\Redlock;

use Closure;
use Patterns\Support\Clock;
use RuntimeException;

/**
 * One independent Redis master, reduced to the commands Redlock needs.
 *
 * This is a simulation: it has the same semantics as the real commands
 * (atomic set-if-absent with expiry, compare-and-delete) without a server.
 */
final class RedisNode
{
    /** @var array<string, array{value: string, expiresAt: int}> */
    private array $keys = [];

    /** Simulates a crashed or unreachable node. */
    public bool $down = false;

    /** Test hook that runs before each command, e.g. to simulate a slow node. */
    public ?Closure $beforeCommand = null;

    public function __construct(
        public readonly string $name,
        private readonly Clock $clock,
    ) {}

    /** `SET key value NX PX ttl` — create only if absent, expiring after $ttlMs. */
    public function setIfAbsent(string $key, string $value, int $ttlMs): bool
    {
        $this->beforeEachCommand();

        if ($this->read($key) !== null) {
            return false;
        }

        $this->keys[$key] = ['value' => $value, 'expiresAt' => $this->clock->nowMs() + $ttlMs];

        return true;
    }

    /**
     * The unlock script: delete the key only if it still holds OUR token.
     * (In real Redis this must be a Lua script so check + delete are atomic.)
     */
    public function deleteIfValueMatches(string $key, string $value): bool
    {
        $this->beforeEachCommand();

        if ($this->read($key) !== $value) {
            return false;
        }

        unset($this->keys[$key]);

        return true;
    }

    public function get(string $key): ?string
    {
        $this->beforeEachCommand();

        return $this->read($key);
    }

    private function read(string $key): ?string
    {
        if (! isset($this->keys[$key])) {
            return null;
        }

        if ($this->keys[$key]['expiresAt'] <= $this->clock->nowMs()) {
            unset($this->keys[$key]); // lazy expiry, like Redis

            return null;
        }

        return $this->keys[$key]['value'];
    }

    private function beforeEachCommand(): void
    {
        if ($this->beforeCommand !== null) {
            ($this->beforeCommand)();
        }

        if ($this->down) {
            throw new RuntimeException("Redis node [{$this->name}] is unreachable.");
        }
    }
}
