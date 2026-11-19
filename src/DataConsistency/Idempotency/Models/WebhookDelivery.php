<?php

namespace Patterns\DataConsistency\Idempotency\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An outgoing webhook we still owe a subscriber.
 *
 * `event_id` never changes across retries. It is what lets the receiver
 * recognise a redelivery.
 */
class WebhookDelivery extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'event_id',
        'url',
        'event_type',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'delivered_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
