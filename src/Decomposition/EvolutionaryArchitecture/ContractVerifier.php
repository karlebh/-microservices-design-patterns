<?php

declare(strict_types=1);

namespace Patterns\Decomposition\EvolutionaryArchitecture;

/**
 * Consumer-driven contract testing: the provider's deploy gate.
 *
 * Every consumer registers what it needs. Before the provider ships a new
 * version, its responses are checked against ALL registered contracts.
 * The provider can then change anything nobody depends on, and finds out
 * immediately — in its own pipeline — when a change would break a consumer.
 */
final class ContractVerifier
{
    /** @var list<ConsumerContract> */
    private array $contracts = [];

    public function register(ConsumerContract $contract): void
    {
        // A consumer publishing a new contract for an endpoint replaces its old one.
        $this->contracts = array_values(array_filter(
            $this->contracts,
            fn (ConsumerContract $existing) => ! ($existing->consumer === $contract->consumer && $existing->endpoint === $contract->endpoint),
        ));

        $this->contracts[] = $contract;
    }

    /**
     * @param  array<string, array>  $providerResponses  endpoint => the response the candidate build returns
     * @return list<string> one line per broken expectation; empty means safe to deploy
     */
    public function verify(array $providerResponses): array
    {
        $violations = [];

        foreach ($this->contracts as $contract) {
            $response = $providerResponses[$contract->endpoint] ?? null;

            if ($response === null) {
                $violations[] = "{$contract->consumer}: endpoint [{$contract->endpoint}] no longer exists";

                continue;
            }

            foreach ($contract->requiredFields as $path => $type) {
                [$found, $value] = $this->read($response, $path);

                if (! $found) {
                    $violations[] = "{$contract->consumer}: [{$contract->endpoint}] is missing field [{$path}]";
                } elseif (get_debug_type($value) !== $type) {
                    $violations[] = "{$contract->consumer}: [{$contract->endpoint}] field [{$path}] must be {$type}, got ".get_debug_type($value);
                }
            }
        }

        return $violations;
    }

    /** @param array<string, array> $providerResponses */
    public function canDeploy(array $providerResponses): bool
    {
        return $this->verify($providerResponses) === [];
    }

    /**
     * Who still relies on a field? An empty answer means it can be removed
     * (the "contract" step of expand/contract).
     *
     * @return list<string>
     */
    public function consumersOf(string $endpoint, string $field): array
    {
        $consumers = [];

        foreach ($this->contracts as $contract) {
            if ($contract->endpoint === $endpoint && isset($contract->requiredFields[$field])) {
                $consumers[] = $contract->consumer;
            }
        }

        return $consumers;
    }

    /** @return array{bool, mixed} [found?, value] for a dot-path like "address.city" */
    private function read(array $data, string $path): array
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }
}
