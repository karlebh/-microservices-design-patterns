<?php

use Illuminate\Support\Facades\Route;
use Patterns\DataConsistency\Idempotency\Http\OrderController;
use Patterns\DataConsistency\Idempotency\Webhooks\WebhookController;

// Served under /api (see Patterns\Support\PatternsServiceProvider).
Route::post('/orders', [OrderController::class, 'store']);
Route::post('/webhooks/orders', [WebhookController::class, 'store']);
