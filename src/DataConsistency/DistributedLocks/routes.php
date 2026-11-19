<?php

use Illuminate\Support\Facades\Route;
use Patterns\DataConsistency\DistributedLocks\Newsletter\NewsLetterController;

// Served under /api (see Patterns\Support\PatternsServiceProvider).
Route::post('/newsletter/send', [NewsLetterController::class, 'send']);
