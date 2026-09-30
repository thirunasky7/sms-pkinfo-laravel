<?php

namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushOutgoingSmsToDeviceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public Message $message)
    {
    }

    public function backoff(): array
    {
        return [15, 60, 180, 600, 1800];
    }

    public function handle(): void
    {
        $this->message->loadMissing('device');
        $device = $this->message->device;

        if (! $device || ! $device->fcm_token) {
            Log::info('FCM skip: no device token', ['message_id' => $this->message->id]);
            $this->message->update([
                'status' => 'queued',
                'error_message' => 'Device offline or missing FCM token',
            ]);

            return;
        }

        $payload = [
            'message' => [
                'token' => $device->fcm_token,
                'data' => [
                    'type' => 'outgoing_sms',
                    'message_id' => (string) $this->message->id,
                    'to' => (string) $this->message->recipient,
                    'body' => (string) $this->message->body,
                ],
                'android' => [
                    'priority' => 'high',
                ],
            ],
        ];

        $serverKey = config('services.fcm.server_key');

        if (! $serverKey) {
            Log::info('FCM stub push', $payload['message']['data']);
            $this->message->update(['status' => 'sending']);

            return;
        }

        $response = Http::withToken($serverKey)
            ->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $device->fcm_token,
                'priority' => 'high',
                'data' => $payload['message']['data'],
            ]);

        if (! $response->successful()) {
            $this->message->increment('retry_count');
            throw new \RuntimeException('FCM push failed: '.$response->body());
        }

        $this->message->update(['status' => 'sending']);
    }
}
