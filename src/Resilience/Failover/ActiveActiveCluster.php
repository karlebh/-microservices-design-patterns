<?php

declare(strict_types=1);

namespace Patterns\Resilience\Failover;

use Patterns\Support\Clock;
use RuntimeException;

/**
 * Active-active: every region accepts reads and writes, and regions
 * replicate to each other asynchronously.
 *
 * There is no primary, hence nothing to promote when a region dies. The
 * price is that two regions can modify the same key before hearing from
 * each other. Here such conflicts are settled by last-write-wins: the
 * version with the newer timestamp survives (region name breaks ties), and
 * the other update is discarded without anyone being told.
 */
final class ActiveActiveCluster
{
    /** @var array<string, array<string, array{value: mixed, timestamp: int, region: string}>> region => key => version */
    private array $data = [];

    /** @var array<string, bool> */
    private array $alive = [];

    /** How many updates were overwritten by a concurrent update from another region. */
    public int $conflictsResolved = 0;

    /** @param list<string> $regions */
    public function __construct(array $regions, private readonly Clock $clock)
    {
        foreach ($regions as $region) {
            $this->data[$region] = [];
            $this->alive[$region] = true;
        }
    }

    public function write(string $region, string $key, mixed $value): void
    {
        $this->assertAlive($region);

        $this->data[$region][$key] = ['value' => $value, 'timestamp' => $this->clock->nowMs(), 'region' => $region];
    }

    public function read(string $region, string $key): mixed
    {
        $this->assertAlive($region);

        return $this->data[$region][$key]['value'] ?? null;
    }

    /**
     * Where a client should go: its preferred (nearest) region if that is
     * up, otherwise any other live one. This is all the "failover" an
     * active-active system needs.
     */
    public function route(string $preferredRegion): string
    {
        if ($this->alive[$preferredRegion]) {
            return $preferredRegion;
        }

        foreach ($this->alive as $region => $alive) {
            if ($alive) {
                return $region;
            }
        }

        throw new RuntimeException('No region is available.');
    }

    /** Asynchronous replication: live regions exchange their data and merge it. */
    public function sync(): void
    {
        $live = array_keys(array_filter($this->alive));

        foreach ($live as $from) {
            foreach ($live as $to) {
                if ($from === $to) {
                    continue;
                }

                foreach ($this->data[$from] as $key => $incoming) {
                    $existing = $this->data[$to][$key] ?? null;

                    if ($existing === null) {
                        $this->data[$to][$key] = $incoming;
                    } elseif ($existing !== $incoming && $this->isNewer($incoming, $existing)) {
                        $this->data[$to][$key] = $incoming; // the local update is lost
                        $this->conflictsResolved++;
                    }
                }
            }
        }
    }

    public function fail(string $region): void
    {
        $this->alive[$region] = false;
    }

    public function recover(string $region): void
    {
        $this->alive[$region] = true;
    }

    /** Last-write-wins: newer timestamp first, then region name so that every region picks the same winner. */
    private function isNewer(array $candidate, array $current): bool
    {
        return [$candidate['timestamp'], $candidate['region']] > [$current['timestamp'], $current['region']];
    }

    private function assertAlive(string $region): void
    {
        if (! $this->alive[$region]) {
            throw new RuntimeException("Region [{$region}] is down.");
        }
    }
}
