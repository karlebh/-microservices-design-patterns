<?php

declare(strict_types=1);

namespace Patterns\Decomposition\ServiceGranularity;

/** A recommendation that shows its working. */
final readonly class GranularityAdvice
{
    public const SEPARATE = 'separate services';

    public const TOGETHER = 'one service';

    /**
     * @param  list<string>  $disintegrators  forces that argue for splitting
     * @param  list<string>  $integrators  forces that argue for keeping together
     */
    public function __construct(
        public string $verdict,
        public array $disintegrators,
        public array $integrators,
    ) {}
}
