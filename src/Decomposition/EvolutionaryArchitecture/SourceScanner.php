<?php

declare(strict_types=1);

namespace Patterns\Decomposition\EvolutionaryArchitecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Derives a dependency graph from real source code, so fitness functions
 * check what the code IS rather than what a diagram says it should be.
 *
 * It reads each PHP file's `namespace` and `use` statements and reduces
 * them to modules: the first $depth segments below the root namespace.
 * For this repository, depth 2 gives "Section\Pattern".
 */
final class SourceScanner
{
    /**
     * @param  string  $directory  the source root, e.g. /path/to/src
     * @param  string  $rootNamespace  the namespace mapped to that directory, e.g. "Patterns"
     * @param  int  $depth  how many namespace segments identify a module
     * @param  list<string>  $ignoreFiles  file names to skip (composition roots that legitimately know everything)
     */
    public static function scan(string $directory, string $rootNamespace, int $depth = 2, array $ignoreFiles = []): DependencyGraph
    {
        $dependencies = [];
        $root = preg_quote($rootNamespace, '/');

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getFilename(), $ignoreFiles, true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! preg_match("/^namespace\s+{$root}\\\\([\w\\\\]+);/m", $source, $namespace)) {
                continue; // not a class file (routes, migrations...)
            }

            $module = self::module($namespace[1], $depth);
            $dependencies[$module] ??= [];

            preg_match_all("/^use\s+{$root}\\\\([\w\\\\]+)/m", $source, $imports);

            foreach ($imports[1] as $import) {
                // Drop the class name: `Support\Clock` lives in namespace `Support`.
                $target = self::module(implode('\\', array_slice(explode('\\', $import), 0, -1)), $depth);

                if ($target !== '' && $target !== $module) {
                    $dependencies[$module][] = $target;
                }
            }
        }

        return DependencyGraph::fromArray(array_map(array_unique(...), $dependencies));
    }

    private static function module(string $namespace, int $depth): string
    {
        return implode('\\', array_slice(explode('\\', $namespace), 0, $depth));
    }
}
