<?php

namespace Patterns\DataArchitecture\ReadReplicas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'completed',
    ];

    protected $casts = [
        'completed' => 'boolean',
    ];

    // The factory lives next to the model instead of database/factories,
    // so Laravel has to be told where to find it.
    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
