<?php

declare(strict_types=1);

namespace Patterns\Resilience\ChaosEngineering;

use Closure;
use Random\Randomizer;
use RuntimeException;

/**
 * Sits between a service and its dependencies and misbehaves on request.
 *
 * Application code calls dependencies THROUGH the injector:
 *
 *   $injector->call('recommendations', fn () => $client->recommend($sku), timeoutMs: 200);
 *
 * With no fault configured this is a plain pass-through. During an
 * experiment it can make a chosen share of those calls fail or become slow,
 * which is how real dependencies fail.
 */
final class FaultInjector
{
    /** @var array<string, array{type: string, probability: float, latencyMs: int, message: string}> target => fault */
    private array $faults = [];

    /** The kill switch: set to false and every fault stops immediately. */
    public bool $enabled = true;

    /** @var array<string, int> target => how many calls were actually affected */
    public array $injected = [];

    public function __construct(private readonly Randomizer $random = new Randomizer) {}

    /** Make calls to $target throw. $probability limits the blast radius (0.1 = one call in ten). */
    public function injectErrors(string $target, float $probability = 1.0, string $message = 'injected failure'): void
    {
        $this->faults[$target] = ['type' => 'error', 'probability' => $probability, 'latencyMs' => 0, 'message' => $message];
    }

    /** Make calls to $target take $latencyMs longer. */
    public function injectLatency(string $target, int $latencyMs, float $probability = 1.0): void
    {
        $this->faults[$target] = ['type' => 'latency', 'probability' => $probability, 'latencyMs' => $latencyMs, 'message' => ''];
    }

    /** Remove the fault on one target, or all faults. */
    public function clear(?string $target = null): void
    {
        if ($target === null) {
            $this->faults = [];
        } else {
            unset($this->faults[$target]);
        }
    }

    public function hasFaults(): bool
    {
        return $this->faults !== [];
    }

    /**
     * Call a dependency, possibly sabotaged.
     *
     * @param  int|null  $timeoutMs  how long the caller is willing to wait; null = forever (which is itself a finding)
     *
     * @throws InjectedTimeout
     * @throws RuntimeException
     */
    public function call(string $target, Closure $operation, ?int $timeoutMs = null): mixed
    {
        $fault = $this->faults[$target] ?? null;

        if (! $this->enabled || $fault === null || $this->random->nextFloat() >= $fault['probability']) {
            return $operation();
        }

        $this->injected[$target] = ($this->injected[$target] ?? 0) + 1;

        if ($fault['type'] === 'error') {
            throw new RuntimeException("[{$target}] {$fault['message']}");
        }

        // Latency: harmless if the caller waits long enough, a timeout if it does not.
        if ($timeoutMs !== null && $fault['latencyMs'] > $timeoutMs) {
            throw new InjectedTimeout("[{$target}] did not answer within {$timeoutMs} ms.");
        }

        return $operation();
    }
}
