<?php

use App\Http\Controllers\DatabaseReplication\TaskController;
use App\Http\Controllers\Idempotency\OrderController;
use Illuminate\Support\Facades\Route;


Route::apiResource('tasks', TaskController::class);
Route::post('/orders', [OrderController::class, 'store']);
