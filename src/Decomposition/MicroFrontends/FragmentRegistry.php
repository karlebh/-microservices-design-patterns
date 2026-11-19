<?php

declare(strict_types=1);

namespace Patterns\Decomposition\MicroFrontends;

/**
 * Maps each slot on the page to the fragment currently deployed for it.
 *
 * In production this is a manifest the shell reads at runtime (a URL per
 * fragment, an import map, a module-federation remote entry). Because the
 * shell looks fragments up here instead of importing them, a team can ship
 * a new version by changing its own entry and nothing else.
 */
final class FragmentRegistry
{
    /** @var array<string, MicroFrontend> */
    private array $slots = [];

    /** A team releases (a new version of) its fragment. */
    public function deploy(string $slot, MicroFrontend $fragment): void
    {
        $this->slots[$slot] = $fragment;
    }

    public function fragmentFor(string $slot): ?MicroFrontend
    {
        return $this->slots[$slot] ?? null;
    }
}
