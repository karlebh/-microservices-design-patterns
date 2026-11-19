<?php

declare(strict_types=1);

namespace Patterns\Resilience\DeadLetterQueue;

use RuntimeException;

/**
 * Thrown by a handler to say: this message is bad in itself (malformed,
 * invalid, refers to something that does not exist). No number of retries
 * will change that, so skip them and dead-letter it straight away.
 */
final class PermanentFailure extends RuntimeException {}
