<?php

namespace Patterns\DataConsistency\DistributedLocks\Newsletter;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class NewsLetterController extends Controller
{
    public const LOCK = 'send-news-letter';

    public function send()
    {
        //the cache is simply the distributed locking
        $lock = Cache::lock(self::LOCK, 60 * 5);

        // Note: Cache::lock(...)->get(fn () => ...) would release the lock as soon
        // as the closure returns, i.e. right after dispatching. The job runs later
        // on a worker, so the lock would no longer protect anything. Instead we
        // keep the lock and hand its owner token to the job, which releases it
        // when the sending is actually finished.
        if (! $lock->get()) {
            return response()->json([
                'message' => 'A newsletter send is already in progress.',
            ], 409);
        }

        SendNewsletterJob::dispatch($lock->owner());

        return response()->json([
            'message' => 'Newsletter send started.',
        ], 202);
    }
}
