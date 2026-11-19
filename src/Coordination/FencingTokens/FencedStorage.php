<?php

declare(strict_types=1);

namespace Patterns\Coordination\FencingTokens;

/**
 * The protected resource (a file store, a database, a downstream service).
 *
 * It is the last line of defence: it does not ask whether the writer
 * THINKS it holds the lock. It compares the writer's token with the
 * highest token it has already accepted, and refuses anything older.
 */
final class FencedStorage
{
    /** @var array<string, mixed> */
    private array $data = [];

    private int $highestToken = 0;

    /** @var list<string> who wrote, in order: makes interleaved writes visible */
    public array $writeLog = [];

    /** @param bool $enforceTokens false = a storage that trusts its clients (to show what goes wrong) */
    public function __construct(private readonly bool $enforceTokens = true) {}

    /**
     * @throws StaleTokenException
     */
    public function write(string $key, mixed $value, int $token, string $writer = 'client'): void
    {
        if ($this->enforceTokens && $token < $this->highestToken) {
            throw new StaleTokenException("Token {$token} is stale: token {$this->highestToken} has already been used.");
        }

        $this->highestToken = max($this->highestToken, $token);
        $this->data[$key] = $value;
        $this->writeLog[] = $writer;
    }

    public function read(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
