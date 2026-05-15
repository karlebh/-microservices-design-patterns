<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class ProcessWebhookCommand extends Command
{
    protected $signature = 'webhooks:process';
    protected $description = 'Process pending webhook deliveries with retry logic';

    public function handle() {}
}
