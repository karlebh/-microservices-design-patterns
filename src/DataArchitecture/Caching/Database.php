<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\Caching;

/** The slow source of truth. Counts how often it is hit, which is what caching is meant to reduce. */
final class Database
{
    public int $reads = 0;

    public int $writes = 0;

    /** @var array<string, mixed> */
    private array $rows = [];

    public function read(string $key): mixed
    {
        $this->reads++;

        return $this->rows[$key] ?? null;
    }

    public function write(string $key, mixed $value): void
    {
        $this->writes++;
        $this->rows[$key] = $value;
    }

    /** Look at a row without counting it as a query (for assertions). */
    public function peek(string $key): mixed
    {
        return $this->rows[$key] ?? null;
    }
}
