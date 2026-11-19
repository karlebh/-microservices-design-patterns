<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\DeliverySemantics;

use RuntimeException;

/** The consumer process died between its two steps (injected by tests). */
final class ProcessCrashed extends RuntimeException {}
