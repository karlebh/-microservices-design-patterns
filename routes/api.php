<?php

use App\Http\Controllers\Idempotency\WebhookController;
use App\Http\Controllers\Indempotency\OrderController;
use App\Models\Idempotency;
use Illuminate\Support\Facades\Route;


Route::post('/orders', [OrderController::class, 'store']);
