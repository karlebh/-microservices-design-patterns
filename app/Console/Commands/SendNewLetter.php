<?php

namespace App\Console\Commands;

use App\Mail\NewsLetterMail;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('send:new-letter')]
#[Description('Send fake news letter')]
class SendNewLetter extends Command
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
            Command::FAILURE;
        }
    }
}
