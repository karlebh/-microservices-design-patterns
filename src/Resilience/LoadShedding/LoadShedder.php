<?php

declare(strict_types=1);

namespace Patterns\Resilience\LoadShedding;

/**
 * Decides, for every incoming request, whether the server can afford it.
 *
 * As utilization rises, work is refused from the least important upwards:
 *
 *   below 70%   everything is admitted
 *   70% - 90%   Sheddable requests are refused
 *   90% - 100%  only Critical requests are admitted
 *   100%        everything is refused
 *
 * so that what the server does manage to do is the work that matters most,
 * and it never accepts more than it can finish in time.
 */
final class LoadShedder
{
    private int $inFlight = 0;

    /** @var array<string, int> priority name => how many were refused */
    public array $shed = ['Critical' => 0, 'Normal' => 0, 'Sheddable' => 0];

    /**
     * @param  int  $capacity  how many requests the server can work on at once
     */
    public function __construct(
        private readonly int $capacity,
        private readonly float $shedSheddableAt = 0.7,
        private readonly float $shedNormalAt = 0.9,
    ) {}

    /**
     * Ask to start working on a request.
     *
     * @return bool true = admitted (call finish() when done); false = shed, answer 503 right away
     */
    public function tryAdmit(Priority $priority): bool
    {
        $utilization = $this->utilization();

        $admit = match ($priority) {
            Priority::Critical => $utilization < 1.0,
            Priority::Normal => $utilization < $this->shedNormalAt,
            Priority::Sheddable => $utilization < $this->shedSheddableAt,
        };

        if (! $admit) {
            $this->shed[$priority->name]++;

            return false;
        }

        $this->inFlight++;

        return true;
    }

    public function finish(): void
    {
        $this->inFlight = max(0, $this->inFlight - 1);
    }

    public function utilization(): float
    {
        return $this->inFlight / $this->capacity;
    }
}
