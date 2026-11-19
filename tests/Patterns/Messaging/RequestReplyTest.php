<?php

declare(strict_types=1);

namespace Tests\Patterns\Messaging;

use LogicException;
use Patterns\Messaging\RequestReply\MessageBus;
use Patterns\Messaging\RequestReply\Requester;
use Patterns\Messaging\RequestReply\Responder;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestReplyTest extends TestCase
{
    private function pricingResponder(MessageBus $bus): Responder
    {
        $prices = ['book' => 4_599, 'pen' => 250];

        return new Responder($bus, 'pricing.requests', fn (string $sku) => ['sku' => $sku, 'price' => $prices[$sku]]);
    }

    public function test_a_reply_finds_its_way_back_to_the_request_that_caused_it(): void
    {
        $bus = new MessageBus;
        $web = new Requester($bus, new FakeClock, 'web-1');
        $pricing = $this->pricingResponder($bus);

        $reply = $web->request('pricing.requests', 'book');

        $this->assertTrue($reply->isPending(), 'Sending does not block: the answer comes later.');

        $pricing->handle();
        $web->collectReplies();

        $this->assertSame(['sku' => 'book', 'price' => 4_599], $reply->value());
    }

    public function test_asking_for_the_value_too_early_is_a_programming_error(): void
    {
        $reply = (new Requester(new MessageBus, new FakeClock, 'web-1'))->request('pricing.requests', 'book');

        $this->expectException(LogicException::class);
        $reply->value();
    }

    public function test_replies_are_matched_by_correlation_id_not_by_arrival_order(): void
    {
        $bus = new MessageBus;
        $web = new Requester($bus, new FakeClock, 'web-1');

        $book = $web->request('pricing.requests', 'book');
        $pen = $web->request('pricing.requests', 'pen');

        // The responder answers the SECOND request first.
        $first = $bus->receive('pricing.requests');
        $second = $bus->receive('pricing.requests');
        $bus->send($second['reply_to'], ['correlation_id' => $second['correlation_id'], 'body' => 'price of pen']);
        $bus->send($first['reply_to'], ['correlation_id' => $first['correlation_id'], 'body' => 'price of book']);

        $web->collectReplies();

        $this->assertSame('price of book', $book->value());
        $this->assertSame('price of pen', $pen->value());
    }

    public function test_a_request_times_out_when_nobody_answers(): void
    {
        $clock = new FakeClock;
        $web = new Requester(new MessageBus, $clock, 'web-1');

        $reply = $web->request('pricing.requests', 'book', timeoutMs: 2_000);

        $clock->advance(1_999);
        $web->collectReplies();
        $this->assertTrue($reply->isPending());

        $clock->advance(1);
        $web->collectReplies();
        $this->assertTrue($reply->hasTimedOut());

        $this->expectException(RuntimeException::class);
        $reply->value();
    }

    public function test_a_reply_that_arrives_after_the_timeout_is_discarded(): void
    {
        $bus = new MessageBus;
        $clock = new FakeClock;
        $web = new Requester($bus, $clock, 'web-1');
        $pricing = $this->pricingResponder($bus);

        $reply = $web->request('pricing.requests', 'book', timeoutMs: 2_000);

        $clock->advance(5_000);  // the responder was slow...
        $pricing->handle();      // ...and answers anyway
        $web->collectReplies();

        $this->assertTrue($reply->hasTimedOut(), 'The caller already gave up; the late answer must not resurrect it.');
        $this->assertSame(1, $web->discardedReplies);
    }

    public function test_each_requester_has_its_own_reply_queue(): void
    {
        $bus = new MessageBus;
        $clock = new FakeClock;
        $webOne = new Requester($bus, $clock, 'web-1');
        $webTwo = new Requester($bus, $clock, 'web-2');
        $pricing = $this->pricingResponder($bus);

        $book = $webOne->request('pricing.requests', 'book');
        $pen = $webTwo->request('pricing.requests', 'pen');

        $pricing->handle();

        $this->assertSame(1, $bus->depth('replies.web-1'));
        $this->assertSame(1, $bus->depth('replies.web-2'));

        $webOne->collectReplies();
        $webTwo->collectReplies();

        $this->assertSame(4_599, $book->value()['price']);
        $this->assertSame(250, $pen->value()['price']);
    }

    public function test_responders_scale_out_as_competing_consumers(): void
    {
        $bus = new MessageBus;
        $web = new Requester($bus, new FakeClock, 'web-1');
        $responderA = $this->pricingResponder($bus);
        $responderB = $this->pricingResponder($bus);

        $replies = [
            $web->request('pricing.requests', 'book'),
            $web->request('pricing.requests', 'pen'),
            $web->request('pricing.requests', 'book'),
        ];

        $this->assertSame(2, $responderA->handle(max: 2));
        $this->assertSame(1, $responderB->handle());

        $web->collectReplies();

        $this->assertSame([4_599, 250, 4_599], array_map(fn ($reply) => $reply->value()['price'], $replies));
    }
}
