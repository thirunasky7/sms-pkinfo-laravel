<?php

namespace App\Jobs;

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

    /**
     * Without a $webhookId the job fans out one job per matching endpoint, so a
     * failing endpoint is retried on its own and never re-delivers to endpoints
     * that already succeeded.
     */
    public function __construct(
        public int $userId,
        public string $eventType,
        public array $payload,
        public ?int $webhookId = null
    ) {
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(): void
    {
        if ($this->webhookId === null) {
            Webhook::query()
                ->where('user_id', $this->userId)
                ->where('event_type', $this->eventType)
                ->where('is_active', true)
                ->pluck('id')
                ->each(fn ($id) => self::dispatch($this->userId, $this->eventType, $this->payload, (int) $id));

            return;
        }

        $webhook = Webhook::query()
            ->where('id', $this->webhookId)
            ->where('user_id', $this->userId)
            ->where('is_active', true)
            ->first();

        if (! $webhook) {
            return;
        }

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
