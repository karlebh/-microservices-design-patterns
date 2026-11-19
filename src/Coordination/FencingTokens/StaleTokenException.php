<?php

declare(strict_types=1);

namespace Patterns\Coordination\FencingTokens;

use RuntimeException;

/** The write carried a token older than one the resource has already accepted. */
final class StaleTokenException extends RuntimeException {}
