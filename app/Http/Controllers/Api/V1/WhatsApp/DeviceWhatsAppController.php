<?php

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\AccountService;
use App\Services\WhatsApp\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Endpoints used by the Android app (device Sanctum token) for the
 * device-based WhatsApp connector. SMS device endpoints are untouched.
 */
class DeviceWhatsAppController extends Controller
{
    public function connect(Request $request, int $id, AccountService $accounts): JsonResponse
    {
        $device = $this->device($request, $id);

        $data = $request->validate([
            'phone_number' => 'required|string|max:32',
            'display_name' => 'nullable|string|max:255',
            'package' => 'nullable|in:com.whatsapp,com.whatsapp.w4b',
            'app_version' => 'nullable|string|max:32',
        ]);

        $account = $accounts->connectDevice($device, $data);

        return response()->json(['account' => $account->toPublicArray()], 201);
    }

    public function disconnect(Request $request, int $id, AccountService $accounts): JsonResponse
    {
        $account = $this->account($this->device($request, $id));
        $accounts->markDisconnected($account, 'Disconnected from device');

        return response()->json(['account' => $account->fresh()->toPublicArray()]);
    }

    /**
     * Heartbeat for WhatsApp: marks the account online and hands the phone
     * the next batch of due messages, atomically claimed as "sending".
     */
    public function sync(Request $request, int $id, WhatsAppMessageService $service): JsonResponse
    {
        $device = $this->device($request, $id);
        $account = $this->account($device);

        $data = $request->validate([
            'ready' => 'nullable|boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        $ready = $data['ready'] ?? true;

        $account->update([
            'last_seen_at' => now(),
            'status' => $ready ? WhatsAppAccount::STATUS_CONNECTED : WhatsAppAccount::STATUS_DISCONNECTED,
            'disconnected_at' => $ready ? null : ($account->disconnected_at ?? now()),
            'last_error' => $ready ? null : ($data['reason'] ?? 'Device reported WhatsApp not ready'),
        ]);

        if (! $ready) {
            return response()->json(['account' => $account->toPublicArray(), 'pending_messages' => []]);
        }

        $candidates = WhatsAppMessage::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('direction', 'outgoing')
            ->where('status', WhatsAppMessage::STATUS_QUEUED)
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderBy('id')
            ->limit((int) config('whatsapp.device.sync_batch_size', 10))
            ->get();

        $pending = $candidates
            ->filter(fn (WhatsAppMessage $m) => $service->claim($m))
            ->map(fn (WhatsAppMessage $m) => [
                'message_id' => $m->message_id,
                'to' => $m->recipient,
                'type' => $m->message_type,
                'body' => $m->body,
                'media_url' => $m->media_url,
                'media_mime' => $m->media_mime,
                'media_filename' => $m->media_filename,
            ])
            ->values();

        return response()->json([
            'account' => $account->fresh()->toPublicArray(),
            'pending_messages' => $pending,
        ]);
    }

    public function updateStatus(Request $request, int $id, string $messageId, WhatsAppMessageService $service): JsonResponse
    {
        $device = $this->device($request, $id);
        $account = $this->account($device);

        $data = $request->validate([
            'status' => 'required|in:sent,delivered,read,failed',
            'error_code' => 'nullable|string|max:64',
            'error' => 'nullable|string|max:1000',
            'retryable' => 'nullable|boolean',
            'at' => 'nullable|date',
        ]);

        $message = WhatsAppMessage::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('message_id', $messageId)
            ->where('direction', 'outgoing')
            ->first();

        if (! $message) {
            return response()->json(['message' => 'Message not found', 'error_code' => 'NOT_FOUND'], 404);
        }

        if ($data['status'] === 'failed') {
            $service->applyFailure(
                $message,
                $data['error_code'] ?? 'DEVICE_SEND_FAILED',
                $data['error'] ?? 'The device could not send the message',
                $data['retryable'] ?? true
            );
        } else {
            $service->applyStatus($message, $data['status'], null, isset($data['at']) ? Carbon::parse($data['at']) : null);
        }

        return response()->json(['message' => $message->fresh()->toApiArray()]);
    }

    public function incoming(Request $request, int $id, WhatsAppMessageService $service): JsonResponse
    {
        $device = $this->device($request, $id);
        $account = $this->account($device);

        $data = $request->validate([
            'sender' => 'required|string|max:64',
            'sender_name' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:4096',
            'message_type' => 'nullable|in:text,image,video,audio,document',
            'client_ref' => 'nullable|string|max:100',
            'received_at' => 'nullable|date',
        ]);

        $result = $service->recordIncoming($account, [
            'sender' => $data['sender'],
            'sender_name' => $data['sender_name'] ?? null,
            'body' => $data['body'] ?? null,
            'message_type' => $data['message_type'] ?? 'text',
            'dedupe_key' => isset($data['client_ref']) ? 'dev:'.$device->id.':'.$data['client_ref'] : null,
            'received_at' => $data['received_at'] ?? null,
        ]);

        return response()->json(
            ['message' => $result['message']->toApiArray(), 'duplicate' => $result['duplicate']],
            $result['duplicate'] ? 200 : 201
        );
    }

    private function device(Request $request, int $id): Device
    {
        /** @var Device $device */
        $device = $request->user();

        abort_if((int) $device->id !== $id, 403, 'Forbidden');

        return $device;
    }

    private function account(Device $device): WhatsAppAccount
    {
        $account = WhatsAppAccount::query()
            ->where('user_id', $device->user_id)
            ->where('connector_type', WhatsAppAccount::CONNECTOR_DEVICE)
            ->where('device_id', $device->id)
            ->whereNull('revoked_at')
            ->first();

        abort_unless($account, 409, 'WhatsApp is not connected on this device');

        return $account;
    }
}
