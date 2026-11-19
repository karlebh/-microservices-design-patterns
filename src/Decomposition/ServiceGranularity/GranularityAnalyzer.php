<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ServiceGranularity;

/**
 * Decides whether two capabilities should be one service or two, by
 * weighing the forces that pull them apart (disintegrators) against the
 * forces that hold them together (integrators).
 *
 * The thresholds are deliberately simple. The value is in being forced to
 * name each force, not in the exact numbers.
 */
final class GranularityAnalyzer
{
    private const VOLATILITY_RATIO = 4;

    private const SCALE_RATIO = 10;

    private const CHATTY_CALLS_PER_REQUEST = 3;

    /**
     * @param  bool  $needAtomicTransaction  must their changes commit or roll back as one?
     * @param  int  $callsPerRequest  how many calls between them a typical request would need if they were separate
     */
    public function evaluate(Capability $a, Capability $b, bool $needAtomicTransaction = false, int $callsPerRequest = 0): GranularityAdvice
    {
        $disintegrators = $this->disintegrators($a, $b);
        $integrators = $this->integrators($a, $b, $needAtomicTransaction, $callsPerRequest);

        return new GranularityAdvice($this->verdict($disintegrators, $integrators), $disintegrators, $integrators);
    }

    /** @return list<string> */
    private function disintegrators(Capability $a, Capability $b): array
    {
        $reasons = [];

        if ($this->ratio($a->changesPerMonth, $b->changesPerMonth) >= self::VOLATILITY_RATIO) {
            $reasons[] = "code volatility: {$a->name} changes {$a->changesPerMonth}x/month, {$b->name} {$b->changesPerMonth}x/month";
        }

        if ($this->ratio($a->peakRequestsPerSecond, $b->peakRequestsPerSecond) >= self::SCALE_RATIO) {
            $reasons[] = "scalability: {$a->name} peaks at {$a->peakRequestsPerSecond} rps, {$b->name} at {$b->peakRequestsPerSecond} rps";
        }

        if ($a->mustStayUp !== $b->mustStayUp) {
            $critical = $a->mustStayUp ? $a : $b;
            $reasons[] = "fault tolerance: {$critical->name} must survive a failure of the other";
        }

        if ($a->handlesSensitiveData !== $b->handlesSensitiveData) {
            $sensitive = $a->handlesSensitiveData ? $a : $b;
            $reasons[] = "security: {$sensitive->name} handles sensitive data and needs tighter access";
        }

        return $reasons;
    }

    /** @return list<string> */
    private function integrators(Capability $a, Capability $b, bool $needAtomicTransaction, int $callsPerRequest): array
    {
        $reasons = [];

        if ($needAtomicTransaction) {
            $reasons[] = 'database transaction: their changes must commit or roll back together';
        }

        $shared = array_values(array_intersect($a->writesTables, $b->writesTables));

        if ($shared !== []) {
            $reasons[] = 'shared data: both write to '.implode(', ', $shared);
        }

        if ($callsPerRequest >= self::CHATTY_CALLS_PER_REQUEST) {
            $reasons[] = "workflow: {$callsPerRequest} calls between them per request would couple their latency and availability";
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $disintegrators
     * @param  list<string>  $integrators
     */
    private function verdict(array $disintegrators, array $integrators): string
    {
        // No reason to split is a reason not to: every boundary has a cost.
        if ($disintegrators === []) {
            return GranularityAdvice::TOGETHER;
        }

        // Integrators win ties. Splitting what must be consistent trades a
        // local transaction for a saga, which is rarely worth it.
        return count($integrators) >= count($disintegrators) || $this->hasHardIntegrator($integrators)
            ? GranularityAdvice::TOGETHER
            : GranularityAdvice::SEPARATE;
    }

    /** @param list<string> $integrators */
    private function hasHardIntegrator(array $integrators): bool
    {
        foreach ($integrators as $reason) {
            if (str_starts_with($reason, 'database transaction') || str_starts_with($reason, 'shared data')) {
                return true;
            }
        }

        return false;
    }

    private function ratio(int $x, int $y): float
    {
        return max($x, $y) / max(1, min($x, $y));
    }
}
