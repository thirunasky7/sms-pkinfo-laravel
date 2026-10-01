<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\SmsMessageReceived;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchWebhookJob;
use App\Jobs\PushOutgoingSmsToDeviceJob;
use App\Models\Device;
use App\Models\Message;
use App\Services\Sms\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => 'required|string|max:32',
            'body' => 'required|string|max:1600',
            'device_id' => 'nullable|integer|exists:devices,id',
            'external_id' => 'nullable|string|max:255',
            'scheduled_at' => 'nullable|date',
        ]);

        $user = $request->user();
        $accountId = method_exists($user, 'ownsAccountId') ? $user->ownsAccountId() : $user->id;

        $result = app(SmsGateway::class)->queue($accountId, $data, $request->attributes->get('subscription'));

        if (! $result['message']) {
            return response()->json(['message' => $result['error']], 422);
        }

        $message = $result['message'];

        return response()->json([
            'message' => $message->fresh(),
        ], 202);
    }

    public function updateStatus(Request $request, int $deviceId, int $messageId): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if ((int) $device->id !== $deviceId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:sent,delivered,failed',
            'error' => 'nullable|string|max:1000',
        ]);

        $message = Message::query()
            ->where('id', $messageId)
            ->where('device_id', $device->id)
            ->firstOrFail();

        $updates = [
            'status' => $data['status'],
            'error_message' => $data['error'] ?? null,
        ];

        if ($data['status'] === 'sent') {
            $updates['sent_at'] = now();
        }

        if ($data['status'] === 'delivered') {
            $updates['delivered_at'] = now();
            $updates['sent_at'] = $message->sent_at ?? now();
        }

        if ($data['status'] === 'failed') {
            $message->increment('retry_count');
            if ($message->retry_count < 3) {
                $updates['status'] = 'queued';
                PushOutgoingSmsToDeviceJob::dispatch($message)->delay(
                    now()->addSeconds(min(900, 30 * (2 ** $message->retry_count)))
                );
            }
        }

        $message->update($updates);

        $event = match ($data['status']) {
            'sent' => 'message.sent',
            'delivered' => 'message.delivered',
            default => 'message.failed',
        };

        DispatchWebhookJob::dispatch($message->user_id, $event, [
            'event' => $event,
            'message' => $message->fresh()->toArray(),
        ]);

        return response()->json(['message' => $message->fresh()]);
    }

    public function incoming(Request $request, int $deviceId): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if ((int) $device->id !== $deviceId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'sender' => 'required|string|max:64',
            'body' => 'required|string|max:1600',
            'received_at' => 'nullable|date',
        ]);

        $message = Message::create([
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'direction' => 'incoming',
            'sender' => $data['sender'],
            'recipient' => $device->sim_number,
            'body' => $data['body'],
            'status' => 'delivered',
            'delivered_at' => $data['received_at'] ?? now(),
        ]);

        DispatchWebhookJob::dispatch($device->user_id, 'message.incoming', [
            'event' => 'message.incoming',
            'message' => $message->toArray(),
        ]);

        event(new SmsMessageReceived($message));

        return response()->json(['message' => $message], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accountId = method_exists($user, 'ownsAccountId') ? $user->ownsAccountId() : $user->id;

        $messages = Message::query()
            ->where('user_id', $accountId)
            ->when($request->direction, fn ($q, $d) => $q->where('direction', $d))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(50);

        return response()->json($messages);
    }
}
