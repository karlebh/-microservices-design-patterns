<?php

namespace App\Enums;

enum IdempotencyStatus: string
{
    case PROCESSING = 'processing';
    case COMPLETED   = 'completed';
    case FAILED      = 'failed';
    case EXPIRED     = 'expired';
}
