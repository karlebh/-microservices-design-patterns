<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ApiComposition\Services;

use RuntimeException;

/** Owns the customers database. */
final class CustomerService
{
    public int $calls = 0;

    /** Simulates an outage. */
    public bool $down = false;

    /** @var array<int, array{id: int, name: string, email: string}> */
    private array $customers = [
        7 => ['id' => 7, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
    ];

    public function find(int $customerId): ?array
    {
        $this->calls++;

        if ($this->down) {
            throw new RuntimeException('Customer service is unavailable.');
        }

        return $this->customers[$customerId] ?? null;
    }
}
