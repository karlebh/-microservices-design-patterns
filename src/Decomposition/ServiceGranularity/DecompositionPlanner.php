<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ServiceGranularity;

/**
 * Proposes where to draw service boundaries.
 *
 * Two strategies, which are best used together: start from business
 * capabilities, then check the result against data ownership.
 */
final class DecompositionPlanner
{
    /**
     * Decompose by business capability: one service per thing the business does.
     *
     * @param  list<Capability>  $capabilities
     * @return array<string, list<string>> business area => capability names
     */
    public function byBusinessCapability(array $capabilities): array
    {
        $services = [];

        foreach ($capabilities as $capability) {
            $services[$capability->businessArea][] = $capability->name;
        }

        ksort($services);

        return $services;
    }

    /**
     * Decompose by data ownership: capabilities that write the same table
     * must end up in the same service, otherwise that table has two owners.
     *
     * The relation is transitive (A shares with B, B shares with C => all
     * three together), so this finds the connected components of the
     * "writes a common table" graph.
     *
     * @param  list<Capability>  $capabilities
     * @return list<list<string>> each inner list is one proposed service
     */
    public function byDataOwnership(array $capabilities): array
    {
        // Union-find: every capability starts as its own group.
        $group = [];

        foreach ($capabilities as $capability) {
            $group[$capability->name] = $capability->name;
        }

        $find = function (string $name) use (&$group, &$find): string {
            if ($group[$name] !== $name) {
                $group[$name] = $find($group[$name]); // path compression
            }

            return $group[$name];
        };

        $firstWriterOf = [];

        foreach ($capabilities as $capability) {
            foreach ($capability->writesTables as $table) {
                if (isset($firstWriterOf[$table])) {
                    // Two writers of one table: merge their groups.
                    $group[$find($capability->name)] = $find($firstWriterOf[$table]);
                } else {
                    $firstWriterOf[$table] = $capability->name;
                }
            }
        }

        $services = [];

        foreach ($capabilities as $capability) {
            $services[$find($capability->name)][] = $capability->name;
        }

        return array_values($services);
    }
}
