<?php

declare(strict_types=1);

namespace Patterns\Messaging\EventDrivenBestPractices;

use Closure;

/**
 * Converts events written in an old schema version to the newest one.
 *
 * Each step knows how to go from version N to N+1 only. To read a v1 event
 * when the current version is v3, the steps are chained: v1 -> v2 -> v3.
 * Handlers downstream are written against the latest version and never
 * contain version checks.
 *
 * The stored event is not modified: upcasting happens on the way in.
 */
final class Upcaster
{
    /** @var array<string, array<int, Closure(array): array>> event type => from version => transform */
    private array $steps = [];

    /** @param Closure(array): array $transform turns version $fromVersion data into version $fromVersion + 1 data */
    public function register(string $type, int $fromVersion, Closure $transform): self
    {
        $this->steps[$type][$fromVersion] = $transform;

        return $this;
    }

    public function upcast(EventEnvelope $event): EventEnvelope
    {
        $version = $event->version;
        $data = $event->data;

        while (isset($this->steps[$event->type][$version])) {
            $data = $this->steps[$event->type][$version]($data);
            $version++;
        }

        return $version === $event->version ? $event : $event->withData($data, $version);
    }

    /** The version handlers should be written against. */
    public function latestVersion(string $type): int
    {
        return isset($this->steps[$type]) ? max(array_keys($this->steps[$type])) + 1 : 1;
    }
}
