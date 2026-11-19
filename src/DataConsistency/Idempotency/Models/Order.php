<?php

namespace Patterns\DataConsistency\Idempotency\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'status',
    ];
}
