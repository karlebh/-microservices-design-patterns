<?php

declare(strict_types=1);

namespace Patterns\Decomposition\EvolutionaryArchitecture;

/**
 * What ONE consumer depends on from ONE endpoint of a provider — written by
 * the consumer's team, verified by the provider's build.
 *
 * It lists only the fields that consumer actually reads. Anything else in
 * the response is none of its business, which is what leaves the provider
 * free to add things.
 */
final readonly class ConsumerContract
{
    /**
     * @param  array<string, string>  $requiredFields  dot-path => type, e.g. ['id' => 'int', 'address.city' => 'string']
     */
    public function __construct(
        public string $consumer,
        public string $endpoint,
        public array $requiredFields,
    ) {}
}
