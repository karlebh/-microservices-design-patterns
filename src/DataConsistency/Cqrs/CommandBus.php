<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Cqrs;

use Closure;
use LogicException;

/**
 * Sends each command to the one handler registered for its class.
 *
 * dispatch() returns nothing on purpose: a command either succeeds or
 * throws. To see the result, ask the query side.
 */
final class CommandBus
{
    /** @var array<class-string, Closure> */
    private array $handlers = [];

    /** @param class-string $commandClass */
    public function register(string $commandClass, Closure $handler): void
    {
        $this->handlers[$commandClass] = $handler;
    }

    public function dispatch(object $command): void
    {
        $handler = $this->handlers[$command::class]
            ?? throw new LogicException('No handler registered for '.$command::class);

        $handler($command);
    }
}
