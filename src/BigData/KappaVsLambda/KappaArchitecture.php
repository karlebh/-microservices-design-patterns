<?php

declare(strict_types=1);

namespace Patterns\BigData\KappaVsLambda;

use Closure;

/**
 * Kappa architecture: one streaming job is the only implementation of the
 * logic, for old data and new data alike.
 *
 * The job is a function (state, event) -> state. Each DEPLOYED VERSION of
 * the job has its own output view and its own position in the log.
 * Reprocessing is not a special mode: it is simply a new version starting
 * from offset 0.
 */
final class KappaArchitecture
{
    /** @var array<string, array{logic: Closure, state: array, offset: int}> version => running job */
    private array $jobs = [];

    /** The version queries are answered from. */
    private string $serving;

    /**
     * @param  Closure(array $state, array $event): array  $logic  the one and only implementation
     */
    public function __construct(private readonly EventLog $log, Closure $logic, string $version = 'v1')
    {
        $this->jobs[$version] = ['logic' => $logic, 'state' => [], 'offset' => 0];
        $this->serving = $version;
    }

    public function ingest(array $event): void
    {
        $this->log->append($event);
        $this->catchUp();
    }

    /**
     * Deploy a new version of the logic. It starts at the BEGINNING of the
     * log and builds its own view, while the old version keeps serving.
     */
    public function deploy(string $version, Closure $logic): void
    {
        $this->jobs[$version] = ['logic' => $logic, 'state' => [], 'offset' => 0];
    }

    /** Let every running job process the events it has not seen yet. */
    public function catchUp(): void
    {
        foreach ($this->jobs as $version => $job) {
            foreach ($this->log->readFrom($job['offset']) as $event) {
                $job['state'] = ($job['logic'])($job['state'], $event);
                $job['offset']++;
            }

            $this->jobs[$version] = $job;
        }
    }

    /** Point readers at a version, once it has replayed the whole log. Then the old one can be deleted. */
    public function switchTo(string $version): void
    {
        $this->serving = $version;
    }

    public function retire(string $version): void
    {
        unset($this->jobs[$version]);
    }

    public function views(string $page, ?string $version = null): int
    {
        return $this->jobs[$version ?? $this->serving]['state']['counts'][$page] ?? 0;
    }

    public function lag(string $version): int
    {
        return $this->log->size() - $this->jobs[$version]['offset'];
    }

    public function servingVersion(): string
    {
        return $this->serving;
    }
}
