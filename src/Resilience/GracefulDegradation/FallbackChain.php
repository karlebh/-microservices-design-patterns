<?php

declare(strict_types=1);

namespace Patterns\Resilience\GracefulDegradation;

use Closure;
use Throwable;

/**
 * An ordered list of ways to answer the same question, from best to worst.
 *
 *   FallbackChain::startingWith('personalised', fn () => $recommender->for($user))
 *       ->orElse('bestsellers', fn () => $cache->get('bestsellers'))
 *       ->orElse('nothing', fn () => [])
 *       ->resolve();
 *
 * The first source that succeeds wins. If none does, the last error is
 * rethrown: that is how you express "this one has no acceptable default".
 */
final class FallbackChain
{
    /** @var list<array{name: string, attempt: Closure}> */
    private array $sources = [];

    private function __construct() {}

    public static function startingWith(string $name, Closure $primary): self
    {
        return (new self)->orElse($name, $primary);
    }

    public function orElse(string $name, Closure $fallback): self
    {
        $this->sources[] = ['name' => $name, 'attempt' => $fallback];

        return $this;
    }

    /**
     * @throws Throwable the error of the last source when every source failed
     */
    public function resolve(): Outcome
    {
        $failures = [];

        foreach ($this->sources as $index => $source) {
            try {
                return new Outcome(
                    value: ($source['attempt'])(),
                    source: $source['name'],
                    degraded: $index > 0,
                    failures: $failures,
                );
            } catch (Throwable $e) {
                $failures[$source['name']] = $e->getMessage();
                $last = $e;
            }
        }

        throw $last;
    }
}
