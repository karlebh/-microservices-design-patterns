<?php

namespace Patterns\DataConsistency\DistributedLocks\Newsletter;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Scheduled in routes/console.php with ->withoutOverlapping(), which is the
 * scheduler taking a distributed lock (in the cache) around this command.
 */
#[Signature('send:new-letter')]
#[Description('Send fake news letter')]
class SendNewsletterCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            User::chunk(500, function ($users) {
                foreach ($users as $user) {
                    Mail::to($user)->send(new NewsLetterMail($user));
                }
            });
        } catch (Throwable $th) {
            $this->warn("Could not send fake newsletters");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
