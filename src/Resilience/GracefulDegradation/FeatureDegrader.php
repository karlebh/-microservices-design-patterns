<?php

declare(strict_types=1);

namespace Patterns\Resilience\GracefulDegradation;

/**
 * Deliberate degradation under load.
 *
 * Each optional feature is registered with the load level at which it is
 * switched off. As load climbs, features disappear in a planned order,
 * leaving their capacity to what matters most. Features that are not
 * registered are essential and are never switched off.
 *
 * The order is decided calmly, in advance, by product and engineering
 * together — not improvised during an incident.
 */
final class FeatureDegrader
{
    private LoadLevel $level = LoadLevel::Normal;

    /** @param array<string, LoadLevel> $disableAt feature => the load level from which it is turned off */
    public function __construct(private readonly array $disableAt) {}

    public function setLevel(LoadLevel $level): void
    {
        $this->level = $level;
    }

    public function isEnabled(string $feature): bool
    {
        $threshold = $this->disableAt[$feature] ?? null;

        return $threshold === null || $this->level->value < $threshold->value;
    }

    /** @return list<string> what users are currently missing */
    public function disabledFeatures(): array
    {
        return array_values(array_filter(
            array_keys($this->disableAt),
            fn (string $feature) => ! $this->isEnabled($feature),
        ));
    }
}
