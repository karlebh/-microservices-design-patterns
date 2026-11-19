<?php

namespace Patterns\DataConsistency\Idempotency\Models;

use Illuminate\Database\Eloquent\Model;
use Patterns\DataConsistency\Idempotency\IdempotencyStatus;

/**
 * One row per Idempotency-Key the API has accepted.
 *
 * The primary key IS the client's key (a UUID), so the database itself
 * guarantees that only one request can ever claim it.
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'status',
        'request_hash',
        'response_code',
        'response',
    ];

    protected $casts = [
        'status' => IdempotencyStatus::class,
        'response' => 'array',
    ];

    public $incrementing = false;
    protected $keyType = 'string';
}
