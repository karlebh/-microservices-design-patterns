<?php

declare(strict_types=1);

namespace Patterns\DataArchitecture\DataLakehouse;

use RuntimeException;

/** Another writer created the next version first. Re-read the table and try again. */
final class ConcurrentCommitException extends RuntimeException {}
