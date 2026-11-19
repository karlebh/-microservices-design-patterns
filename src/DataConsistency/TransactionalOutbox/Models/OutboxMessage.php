<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An event that has been committed to the database but not necessarily
 * handed to the broker yet. `published_at === null` means "still to send".
 */
class OutboxMessage extends Model
{
    protected $table = 'outbox_messages';

    protected $fillable = [
        'message_id',
        'aggregate_type',
        'aggregate_id',
        'type',
        'payload',
        'attempts',
        'last_error',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
