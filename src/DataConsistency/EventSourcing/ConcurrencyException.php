<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\EventSourcing;

use RuntimeException;

/** The stream changed between loading the aggregate and saving it. Reload and retry. */
final class ConcurrencyException extends RuntimeException
{
    public static function versionMismatch(string $stream, int $expected, int $actual): self
    {
        return new self("Stream [{$stream}] is at version {$actual}, but version {$expected} was expected.");
    }
}
