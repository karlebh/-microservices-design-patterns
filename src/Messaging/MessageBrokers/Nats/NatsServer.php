<?php

declare(strict_types=1);

namespace Patterns\Messaging\MessageBrokers\Nats;

use Closure;
use RuntimeException;

/**
 * The (core) NATS model in miniature.
 *
 *  - Messages are addressed to SUBJECTS: dot-separated names like
 *    `orders.eu.created`. Subscribers may use wildcards:
 *       `*` matches exactly one token, `>` matches one or more trailing tokens.
 *  - Core NATS stores NOTHING. A message goes to whoever is subscribed at
 *    that instant; with no subscriber it is simply gone (at-most-once).
 *    Persistence and replay are a separate layer (JetStream).
 *  - A QUEUE GROUP load-balances: one member of the group gets each message.
 *  - REQUEST-REPLY is built in: the request carries a private "inbox"
 *    subject and the responder publishes its answer there.
 */
final class NatsServer
{
    /** @var array<int, array{subject: string, queueGroup: ?string, handler: Closure}> */
    private array $subscriptions = [];

    /** @var array<string, int> "subject|group" => rotating index, to spread load within a queue group */
    private array $rotation = [];

    private int $nextSubscriptionId = 1;

    private int $nextInbox = 1;

    /**
     * @param  Closure(mixed $data, string $subject, ?string $replyTo): void  $handler
     * @return int subscription id, for unsubscribe()
     */
    public function subscribe(string $subject, Closure $handler, ?string $queueGroup = null): int
    {
        $id = $this->nextSubscriptionId++;
        $this->subscriptions[$id] = ['subject' => $subject, 'queueGroup' => $queueGroup, 'handler' => $handler];

        return $id;
    }

    public function unsubscribe(int $subscriptionId): void
    {
        unset($this->subscriptions[$subscriptionId]);
    }

    /**
     * @return int how many subscribers received the message. 0 means nobody
     *             was listening and the message no longer exists anywhere.
     */
    public function publish(string $subject, mixed $data, ?string $replyTo = null): int
    {
        $receivers = [];
        $groups = [];

        foreach ($this->subscriptions as $subscription) {
            if (! $this->matches($subscription['subject'], $subject)) {
                continue;
            }

            if ($subscription['queueGroup'] === null) {
                $receivers[] = $subscription;   // plain subscribers all get a copy
            } else {
                $groups[$subscription['queueGroup']][] = $subscription; // one per group, chosen below
            }
        }

        foreach ($groups as $group => $members) {
            $turn = $this->rotation["{$subject}|{$group}"] = ($this->rotation["{$subject}|{$group}"] ?? -1) + 1;
            $receivers[] = $members[$turn % count($members)];
        }

        foreach ($receivers as $subscription) {
            ($subscription['handler'])($data, $subject, $replyTo);
        }

        return count($receivers);
    }

    /**
     * Request-reply: subscribe to a throwaway inbox subject, publish the
     * request with that inbox as reply-to, and take the first answer.
     */
    public function request(string $subject, mixed $data): mixed
    {
        $inbox = '_INBOX.'.($this->nextInbox++);
        $answered = false;
        $response = null;

        $subscription = $this->subscribe($inbox, function (mixed $data) use (&$answered, &$response) {
            if (! $answered) {
                $answered = true;
                $response = $data;
            }
        });

        $this->publish($subject, $data, replyTo: $inbox);
        $this->unsubscribe($subscription);

        if (! $answered) {
            throw new RuntimeException("No responders available for [{$subject}].");
        }

        return $response;
    }

    private function matches(string $pattern, string $subject): bool
    {
        $patternTokens = explode('.', $pattern);
        $subjectTokens = explode('.', $subject);

        foreach ($patternTokens as $i => $token) {
            if ($token === '>') {
                return count($subjectTokens) > $i; // at least one more token must follow
            }

            if (! isset($subjectTokens[$i]) || ($token !== '*' && $token !== $subjectTokens[$i])) {
                return false;
            }
        }

        return count($patternTokens) === count($subjectTokens);
    }
}
