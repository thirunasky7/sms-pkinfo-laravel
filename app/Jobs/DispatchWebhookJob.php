<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $userId,
        public string $eventType,
        public array $payload
    ) {
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(): void
    {
        $webhooks = Webhook::query()
            ->where('user_id', $this->userId)
            ->where('event_type', $this->eventType)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            $body = json_encode($this->payload);
            $signature = hash_hmac('sha256', $body, $webhook->secret);

            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-SMS-Gateway-Event' => $this->eventType,
                    'X-SMS-Gateway-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            if (! $response->successful()) {
                Log::warning('Webhook delivery failed', [
                    'webhook_id' => $webhook->id,
                    'status' => $response->status(),
                ]);
                throw new \RuntimeException('Webhook delivery failed');
            }
        }
    }
}
