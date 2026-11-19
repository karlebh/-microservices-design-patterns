<?php

use Illuminate\Support\Facades\Route;
use Patterns\DataArchitecture\ReadReplicas\Http\TaskController;

// Served under /api (see Patterns\Support\PatternsServiceProvider).
Route::apiResource('tasks', TaskController::class);
