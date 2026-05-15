<?php

namespace App\Http\Controllers\DistributedLocking;

use App\Console\Commands\SendNewLetter;
use App\Http\Controllers\Controller;
use App\Jobs\SendNewLetter as JobsSendNewLetter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NewsLetterController extends Controller
{
    public function send()
    {
        //the cache is simply the distributed locking
        Cache::lock("send-news-letter", 60 * 5)->get(function () {
            JobsSendNewLetter::dispatch();
        });
    }
}
