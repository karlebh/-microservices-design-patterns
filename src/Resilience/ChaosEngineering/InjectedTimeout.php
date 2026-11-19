<?php

declare(strict_types=1);

namespace Patterns\Resilience\ChaosEngineering;

use RuntimeException;

/** The injected latency was longer than the caller was prepared to wait. */
final class InjectedTimeout extends RuntimeException {}
