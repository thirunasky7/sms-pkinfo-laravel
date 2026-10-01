<?php

namespace App\Services\Sms;

use App\Jobs\PushOutgoingSmsToDeviceJob;
use App\Models\Device;
use App\Models\Message;
use App\Models\Subscription;

class SmsGateway
{
    /**
     * Queue an outgoing SMS. Logic moved verbatim from MessageController@send
     * so the message router and automation rules share one SMS path.
     *
     * @param  array{to: string, body: string, device_id?: int|null, external_id?: string|null, scheduled_at?: string|null}  $data
     * @return array{message: ?Message, error: ?string}
     */
    public function queue(int $accountId, array $data, ?Subscription $subscription = null): array
    {
        $deviceQuery = Device::query()
            ->where('user_id', $accountId)
            ->whereNotIn('status', ['disabled', 'paused']);

        if (! empty($data['device_id'])) {
            $device = (clone $deviceQuery)->where('id', $data['device_id'])->first();
        } else {
            $device = (clone $deviceQuery)
                ->orderByDesc('last_sync_at')
                ->first();
        }

        if (! $device) {
            return ['message' => null, 'error' => 'No available device'];
        }

        $message = Message::create([
            'user_id' => $accountId,
            'device_id' => $device->id,
            'direction' => 'outgoing',
            'sender' => $device->sim_number,
            'recipient' => $data['to'],
            'body' => $data['body'],
            'status' => 'queued',
            'external_id' => $data['external_id'] ?? null,
            'queued_at' => now(),
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);

        if ($subscription) {
            $subscription->increment('sms_used');
        }

        if (empty($data['scheduled_at']) || now()->gte($data['scheduled_at'])) {
            PushOutgoingSmsToDeviceJob::dispatch($message);
        }

        return ['message' => $message, 'error' => null];
    }
}
