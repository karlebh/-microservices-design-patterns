<?php

namespace Patterns\DataConsistency\DistributedLocks\Newsletter;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNewsletterJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  string|null  $lockOwner  owner token of the lock taken by the dispatcher
     */
    public function __construct(public ?string $lockOwner = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            User::chunk(500, function ($users) {
                foreach ($users as $user) {
                    Mail::to($user)->send(new NewsLetterMail($user));
                }
            });
        } catch (Throwable $th) {
            Log::error("Could not send fake newsletters", ['error' => $th->getMessage()]);
        } finally {
            // Release the lock the controller acquired. restoreLock() rebuilds the
            // lock from its owner token, so only the rightful owner can release it.
            // If this worker dies before getting here, the TTL frees the lock.
            if ($this->lockOwner !== null) {
                Cache::restoreLock(NewsLetterController::LOCK, $this->lockOwner)->release();
            }
        }
    }
}
