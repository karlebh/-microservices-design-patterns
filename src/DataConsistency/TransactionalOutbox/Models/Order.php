<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\TransactionalOutbox\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'outbox_orders';

    protected $fillable = ['customer', 'total_cents', 'status'];
}
