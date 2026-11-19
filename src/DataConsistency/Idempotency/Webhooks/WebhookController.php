<?php

namespace Patterns\DataConsistency\Idempotency\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Patterns\DataConsistency\Idempotency\IdempotentConsumer;
use Patterns\DataConsistency\Idempotency\Models\Order;

/**
 * The receiving end of a webhook: an idempotent consumer.
 *
 * Senders deliver at-least-once (see ProcessWebhookCommand), so the same
 * event can show up several times. Each event has a stable `id`; we process
 * a given id once and simply acknowledge every repeat.
 */
class WebhookController extends Controller
{
    public function store(Request $request, IdempotentConsumer $consumer)
    {
        $event = $request->validate([
            'id' => ['required', 'string'],
            'type' => ['required', 'string'],
            'data' => ['required', 'array'],
        ]);

        // Events we don't care about are acknowledged so the sender stops retrying.
        if ($event['type'] !== 'order.created') {
            return response()->json(['status' => 'ignored']);
        }

        $data = validator($event['data'], [
            'user_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ])->validate();

        $handled = $consumer->process('orders-webhook', $event['id'], function () use ($data) {
            Order::create([
                'user_id' => $data['user_id'],
                'amount' => $data['amount'],
                'status' => 'pending',
            ]);
        });

        // A duplicate is a SUCCESS from the sender's point of view:
        // the order exists, which is all it wanted to make sure of.
        return response()->json(
            ['status' => $handled ? 'processed' : 'duplicate_ignored'],
            $handled ? 201 : 200
        );
    }
}
