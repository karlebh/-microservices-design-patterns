<?php

declare(strict_types=1);

namespace Patterns\Decomposition\StranglerFig;

use Closure;
use Throwable;

/**
 * The facade that sits in front of the legacy system for the whole
 * migration. Clients only ever talk to it, so features can move behind
 * its back, one route at a time.
 */
final class StranglerFacade
{
    /** @var array<string, int> path prefix => percentage of users served by the new system */
    private array $migrated = [];

    /** @var array<string, true> path prefixes in shadow mode */
    private array $shadowed = [];

    /** @var list<array{path: string, legacy: mixed, modern: mixed}> differences found while shadowing */
    public array $mismatches = [];

    /** @var list<string> paths where the new system failed and legacy stepped in */
    public array $fallbacks = [];

    /**
     * @param  Closure(string $path): mixed  $legacy  the monolith
     * @param  Closure(string $path): mixed  $modern  the new system
     */
    public function __construct(
        private readonly Closure $legacy,
        private readonly Closure $modern,
    ) {}

    /**
     * Send a route to the new system, for all users or a percentage of them.
     * Calling it again with a different percentage ramps up, or rolls back with 0.
     */
    public function migrate(string $pathPrefix, int $percentage = 100): void
    {
        $this->migrated[$pathPrefix] = max(0, min(100, $percentage));
        unset($this->shadowed[$pathPrefix]);
    }

    /**
     * Dark launch: call both systems, answer from legacy, compare the results.
     * Use for read-only routes only — a write would be performed twice.
     */
    public function shadow(string $pathPrefix): void
    {
        $this->shadowed[$pathPrefix] = true;
    }

    /** @return array{servedBy: string, body: mixed} */
    public function handle(string $path, string $userId = 'anonymous'): array
    {
        if ($this->routesToModern($path, $userId)) {
            try {
                return ['servedBy' => 'modern', 'body' => ($this->modern)($path)];
            } catch (Throwable) {
                // Safety net while the new implementation is still earning trust.
                $this->fallbacks[] = $path;
            }
        }

        $body = ($this->legacy)($path);

        if ($this->matchingPrefix($path, array_keys($this->shadowed)) !== null) {
            $this->compareWithModern($path, $body);
        }

        return ['servedBy' => 'legacy', 'body' => $body];
    }

    /**
     * How far along the migration is.
     *
     * @param  list<string>  $allRoutes  every route prefix the legacy system used to serve
     */
    public function progress(array $allRoutes): float
    {
        $done = array_filter($allRoutes, fn (string $route) => ($this->migrated[$route] ?? 0) === 100);

        return fdiv(count($done), count($allRoutes));
    }

    private function routesToModern(string $path, string $userId): bool
    {
        $prefix = $this->matchingPrefix($path, array_keys($this->migrated));

        if ($prefix === null) {
            return false;
        }

        // A stable hash puts each user in a fixed bucket 0-99, so a user who
        // was moved to the new system stays there as the percentage grows.
        return crc32($userId) % 100 < $this->migrated[$prefix];
    }

    private function compareWithModern(string $path, mixed $legacyBody): void
    {
        try {
            $modernBody = ($this->modern)($path);
        } catch (Throwable $e) {
            $modernBody = 'error: '.$e->getMessage();
        }

        if ($modernBody !== $legacyBody) {
            $this->mismatches[] = ['path' => $path, 'legacy' => $legacyBody, 'modern' => $modernBody];
        }
    }

    /**
     * The longest prefix wins, so `/orders/export` can be handled
     * differently from the rest of `/orders`.
     *
     * @param  list<string>  $prefixes
     */
    private function matchingPrefix(string $path, array $prefixes): ?string
    {
        $best = null;

        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix) && strlen($prefix) > strlen($best ?? '')) {
                $best = $prefix;
            }
        }

        return $best;
    }
}
