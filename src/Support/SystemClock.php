<?php

declare(strict_types=1);

namespace Patterns\Support;

/** Real wall-clock time. Use this outside of tests. */
final class SystemClock implements Clock
{
    public function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
