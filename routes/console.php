<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


//laravel handles distributed locking with the ->withoutOverlapping() method
// (the command lives in src/DataConsistency/DistributedLocks/Newsletter)
Schedule::command('send:new-letter')->daily()->withoutOverlapping();
