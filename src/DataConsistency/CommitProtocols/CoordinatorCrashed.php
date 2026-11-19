<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\CommitProtocols;

use RuntimeException;

/** The coordinator process died in the middle of the protocol (injected by tests). */
final class CoordinatorCrashed extends RuntimeException {}
