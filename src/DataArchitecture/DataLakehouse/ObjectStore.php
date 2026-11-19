<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\DataLakehouse;

use OutOfBoundsException;

/**
 * An in-memory stand-in for object storage (S3, GCS, ADLS).
 *
 * Objects are written whole and never modified. The only coordination
 * primitive is putIfAbsent(): "create this object unless it already
 * exists", which the store performs atomically. A table format needs
 * nothing more than that to implement transactions.
 */
final class ObjectStore
{
    /** @var array<string, string> path => content */
    private array $objects = [];

    /**
     * @return bool false if an object already exists at that path (nothing is written)
     */
    public function putIfAbsent(string $path, string $content): bool
    {
        if (isset($this->objects[$path])) {
            return false;
        }

        $this->objects[$path] = $content;

        return true;
    }

    public function get(string $path): string
    {
        return $this->objects[$path] ?? throw new OutOfBoundsException("No object at [{$path}].");
    }

    public function exists(string $path): bool
    {
        return isset($this->objects[$path]);
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
    }

    /** @return list<string> paths starting with the prefix, sorted */
    public function list(string $prefix): array
    {
        $paths = array_values(array_filter(
            array_keys($this->objects),
            fn (string $path) => str_starts_with($path, $prefix),
        ));

        sort($paths);

        return $paths;
    }
}
