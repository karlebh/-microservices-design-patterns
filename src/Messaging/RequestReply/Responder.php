<?php

declare(strict_types=1);

namespace Patterns\Messaging\RequestReply;

use Closure;

/**
 * The answering side.
 *
 * It has no idea who the requesters are. Each request says where its
 * answer should go (`reply_to`) and which id to put on it (`correlation_id`).
 */
final class Responder
{
    /** @param Closure(mixed): mixed $handler turns a request body into a reply body */
    public function __construct(
        private readonly MessageBus $bus,
        private readonly string $requestQueue,
        private readonly Closure $handler,
    ) {}

    /** @return int number of requests answered */
    public function handle(int $max = PHP_INT_MAX): int
    {
        $handled = 0;

        while ($handled < $max && ($request = $this->bus->receive($this->requestQueue)) !== null) {
            $this->bus->send($request['reply_to'], [
                'correlation_id' => $request['correlation_id'], // echoed back untouched
                'body' => ($this->handler)($request['body']),
            ]);

            $handled++;
        }

        return $handled;
    }
}
