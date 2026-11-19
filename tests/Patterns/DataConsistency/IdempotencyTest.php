<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Patterns\DataConsistency\Idempotency\IdempotentConsumer;
use Patterns\DataConsistency\Idempotency\Models\Order;
use Patterns\DataConsistency\Idempotency\Models\WebhookDelivery;
use RuntimeException;
use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    // ---- Idempotency keys: client -> API -------------------------------------------------

    public function test_a_retry_with_the_same_key_replays_the_response_instead_of_creating_a_second_order(): void
    {
        $key = (string) Str::uuid();
        $body = ['user_id' => 7, 'amount' => 49.99];

        $first = $this->postJson('/api/orders', $body, ['Idempotency-Key' => $key]);
        $retry = $this->postJson('/api/orders', $body, ['Idempotency-Key' => $key]);

        $first->assertCreated();
        $retry->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $retry->json(), 'The client sees the exact same answer.');
        $this->assertSame(1, Order::count(), 'The side effect happened once.');
    }

    public function test_different_keys_are_different_operations(): void
    {
        $body = ['user_id' => 7, 'amount' => 49.99];

        $this->postJson('/api/orders', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/api/orders', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->assertSame(2, Order::count());
    }

    public function test_reusing_a_key_for_a_different_request_is_rejected(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/orders', ['user_id' => 7, 'amount' => 10], ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/orders', ['user_id' => 7, 'amount' => 9999], ['Idempotency-Key' => $key])->assertStatus(422);

        $this->assertSame(1, Order::count());
    }

    public function test_a_key_cannot_be_used_to_read_another_callers_response(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/orders', ['user_id' => 7, 'amount' => 10], ['Idempotency-Key' => $key])->assertCreated();

        $this->postJson('/api/orders', ['user_id' => 8, 'amount' => 10], ['Idempotency-Key' => $key])
            ->assertStatus(409)
            ->assertJsonMissing(['order_id' => 1]);
    }

    public function test_the_key_is_mandatory_and_must_be_a_uuid(): void
    {
        $this->postJson('/api/orders', ['user_id' => 7, 'amount' => 10])->assertStatus(422);
        $this->postJson('/api/orders', ['user_id' => 7, 'amount' => 10], ['Idempotency-Key' => 'not-a-uuid'])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    // ---- Idempotent consumer: broker / webhook -> service --------------------------------

    public function test_a_redelivered_webhook_is_acknowledged_but_applied_only_once(): void
    {
        $event = ['id' => 'evt_123', 'type' => 'order.created', 'data' => ['user_id' => 7, 'amount' => 25]];

        $this->postJson('/api/webhooks/orders', $event)->assertCreated()->assertJson(['status' => 'processed']);
        $this->postJson('/api/webhooks/orders', $event)->assertOk()->assertJson(['status' => 'duplicate_ignored']);
        $this->postJson('/api/webhooks/orders', $event)->assertOk();

        $this->assertSame(1, Order::count());
    }

    public function test_a_failed_handler_does_not_mark_the_message_as_processed(): void
    {
        $consumer = new IdempotentConsumer;
        $runs = 0;

        try {
            $consumer->process('billing', 'msg-1', function () use (&$runs) {
                $runs++;

                throw new RuntimeException('database hiccup');
            });
        } catch (RuntimeException) {
            // the broker will redeliver
        }

        // The redelivery is NOT treated as a duplicate, because the marker rolled back with the failure.
        $this->assertTrue($consumer->process('billing', 'msg-1', function () use (&$runs) {
            $runs++;
        }));
        $this->assertFalse($consumer->process('billing', 'msg-1', fn () => $runs++));

        $this->assertSame(2, $runs, 'One failed attempt plus exactly one successful one.');
    }

    public function test_each_consumer_tracks_its_own_duplicates(): void
    {
        $consumer = new IdempotentConsumer;

        $this->assertTrue($consumer->process('billing', 'msg-1', fn () => null));
        $this->assertTrue($consumer->process('shipping', 'msg-1', fn () => null), 'Same message, different consumer.');
    }

    // ---- The at-least-once sender that makes all of the above necessary ------------------

    public function test_the_sender_retries_with_backoff_until_the_receiver_confirms(): void
    {
        Http::fake(['*' => Http::sequence()->pushStatus(500)->pushStatus(200)]);

        $delivery = WebhookDelivery::create([
            'event_id' => (string) Str::uuid(),
            'url' => 'https://shop.test/hooks',
            'event_type' => 'order.created',
            'payload' => ['user_id' => 7, 'amount' => 25],
        ]);

        $this->artisan('webhooks:process')->assertSuccessful();

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertTrue($delivery->next_attempt_at->isFuture(), 'The retry is scheduled, not immediate.');

        // Too early: nothing is due yet.
        $this->artisan('webhooks:process');
        Http::assertSentCount(1);

        $this->travel(30)->seconds();
        $this->artisan('webhooks:process');

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(2, $delivery->attempts);

        // Both attempts carried the same event id, so the receiver could dedupe.
        Http::assertSent(fn ($request) => $request['id'] === $delivery->event_id);
        Http::assertSentCount(2);
    }

    public function test_the_sender_gives_up_after_the_maximum_number_of_attempts(): void
    {
        Http::fake(['*' => Http::response(null, 503)]);

        $delivery = WebhookDelivery::create([
            'event_id' => (string) Str::uuid(),
            'url' => 'https://shop.test/hooks',
            'event_type' => 'order.created',
            'payload' => [],
        ]);

        for ($i = 0; $i < WebhookDelivery::MAX_ATTEMPTS + 2; $i++) {
            $this->artisan('webhooks:process');
            $this->travel(1)->hours();
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(WebhookDelivery::MAX_ATTEMPTS, $delivery->attempts);
        Http::assertSentCount(WebhookDelivery::MAX_ATTEMPTS);
    }
}
