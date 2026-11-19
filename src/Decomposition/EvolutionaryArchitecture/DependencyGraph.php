<?php

declare(strict_types=1);

namespace Patterns\Decomposition\EvolutionaryArchitecture;

/**
 * "Who depends on whom", plus fitness functions over it.
 *
 * Nodes can be services, modules, layers or namespaces. Each method here
 * answers one objective yes/no question about the architecture, which is
 * what makes it usable as an automated check in CI.
 */
final class DependencyGraph
{
    /** @var array<string, array<string, true>> from => set of nodes it depends on */
    private array $edges = [];

    /** @param array<string, list<string>> $dependencies from => depends on */
    public static function fromArray(array $dependencies): self
    {
        $graph = new self;

        foreach ($dependencies as $from => $targets) {
            $graph->edges[$from] ??= [];

            foreach ($targets as $to) {
                $graph->addDependency($from, $to);
            }
        }

        return $graph;
    }

    public function addDependency(string $from, string $to): self
    {
        $this->edges[$from][$to] = true;
        $this->edges[$to] ??= [];

        return $this;
    }

    /** @return list<string> */
    public function dependenciesOf(string $node): array
    {
        return array_keys($this->edges[$node] ?? []);
    }

    /**
     * Fitness function: there must be no dependency cycles.
     *
     * A cycle means none of its members can be understood, tested or
     * deployed on its own: they are one unit pretending to be several.
     *
     * @return list<list<string>> each cycle as the path that closes it, e.g. [a, b, c, a]
     */
    public function cycles(): array
    {
        $cycles = [];
        $finished = [];

        $visit = function (string $node, array $path) use (&$visit, &$cycles, &$finished): void {
            $position = array_search($node, $path, true);

            if ($position !== false) {
                // We walked back onto our own path: everything from there is a cycle.
                $cycle = $this->startingAtSmallest(array_slice($path, $position));
                $cycles[implode(' -> ', $cycle)] = [...$cycle, $cycle[0]];

                return;
            }

            if (isset($finished[$node])) {
                return;
            }

            foreach ($this->dependenciesOf($node) as $next) {
                $visit($next, [...$path, $node]);
            }

            $finished[$node] = true;
        };

        foreach (array_keys($this->edges) as $node) {
            $visit($node, []);
        }

        return array_values($cycles);
    }

    /**
     * Fitness function: layering / isolation rules.
     *
     * @param  array<string, list<string>>  $rules  node prefix => prefixes it must NOT depend on
     * @return list<string> offending edges as "from -> to"
     */
    public function forbiddenDependencies(array $rules): array
    {
        $offences = [];

        foreach ($this->edges as $from => $targets) {
            foreach ($rules as $fromPrefix => $forbiddenPrefixes) {
                if (! str_starts_with($from, $fromPrefix)) {
                    continue;
                }

                foreach (array_keys($targets) as $to) {
                    foreach ($forbiddenPrefixes as $forbidden) {
                        if (str_starts_with($to, $forbidden)) {
                            $offences[] = "{$from} -> {$to}";
                        }
                    }
                }
            }
        }

        return $offences;
    }

    /** Fitness function input: how many things a node depends on directly. */
    public function fanOut(string $node): int
    {
        return count($this->edges[$node] ?? []);
    }

    /**
     * Everything that directly or indirectly depends on a node: the blast
     * radius of changing it.
     *
     * @return list<string>
     */
    public function dependentsOf(string $node): array
    {
        $dependents = [];
        $queue = [$node];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->edges as $from => $targets) {
                if (isset($targets[$current]) && ! isset($dependents[$from])) {
                    $dependents[$from] = true;
                    $queue[] = $from;
                }
            }
        }

        $names = array_keys($dependents);
        sort($names);

        return $names;
    }

    /**
     * Rotate a cycle so it begins at its alphabetically first node. The same
     * cycle discovered from different starting points then looks identical
     * and is counted once.
     *
     * @param  list<string>  $cycle
     * @return list<string>
     */
    private function startingAtSmallest(array $cycle): array
    {
        $start = array_search(min($cycle), $cycle, true);

        return [...array_slice($cycle, $start), ...array_slice($cycle, 0, $start)];
    }
}
