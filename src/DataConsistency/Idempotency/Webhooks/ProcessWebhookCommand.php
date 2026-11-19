<?php

namespace Patterns\DataConsistency\Idempotency\Webhooks;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Patterns\DataConsistency\Idempotency\Models\WebhookDelivery;
use Throwable;

/**
 * The sending end of a webhook: at-least-once delivery.
 *
 * We keep retrying until the receiver answers 2xx. If the receiver did the
 * work but its response got lost, we will send the same event again — which
 * is exactly why the receiver has to be idempotent.
 */
class ProcessWebhookCommand extends Command
{
    protected $signature = 'webhooks:process {--limit=50 : Maximum deliveries to attempt in this run}';
    protected $description = 'Process pending webhook deliveries with retry logic';

    public function handle()
    {
        $due = WebhookDelivery::query()
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', Carbon::now());
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $delivery) {
            $error = $this->attempt($delivery);
            $delivery->attempts++;

            if ($error === null) {
                $delivery->status = 'delivered';
                $delivery->delivered_at = Carbon::now();
                $delivery->next_attempt_at = null;
                $delivery->last_error = null;
            } elseif ($delivery->attempts >= WebhookDelivery::MAX_ATTEMPTS) {
                // Give up. A real system would alert or move this to a dead letter queue.
                $delivery->status = 'failed';
                $delivery->next_attempt_at = null;
                $delivery->last_error = $error;
            } else {
                // Exponential backoff: 20s, 40s, 80s, 160s...
                $delivery->next_attempt_at = Carbon::now()->addSeconds(10 * (2 ** $delivery->attempts));
                $delivery->last_error = $error;
            }

            $delivery->save();

            $this->line("{$delivery->event_id}: {$delivery->status} (attempt {$delivery->attempts})");
        }

        return self::SUCCESS;
    }

    /** @return string|null null on success, otherwise why the attempt failed */
    private function attempt(WebhookDelivery $delivery): ?string
    {
        try {
            $response = Http::timeout(5)
                // The SAME id on every retry: the receiver dedupes on it.
                ->withHeaders(['X-Webhook-Id' => $delivery->event_id])
                ->post($delivery->url, [
                    'id' => $delivery->event_id,
                    'type' => $delivery->event_type,
                    'data' => $delivery->payload,
                ]);

            return $response->successful() ? null : 'HTTP '.$response->status();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
