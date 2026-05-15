<?php

namespace App\Jobs;

use App\Mail\NewsLetterMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNewLetter implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

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
            Log::error("Could not send fake newsletters", []);
        }
    }
}
