<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DevicePairingToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function createPairingToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $accountId = $user->ownsAccountId();

        $token = Str::random(32);

        $pairing = DevicePairingToken::create([
            'user_id' => $accountId,
            'token' => $token,
            'expires_at' => now()->addMinutes(15),
        ]);

        $payload = json_encode([
            'token' => $pairing->token,
            'api' => rtrim(config('app.url'), '/').'/api/v1',
        ]);

        return response()->json([
            'token' => $pairing->token,
            'expires_at' => $pairing->expires_at->toIso8601String(),
            'qr_payload' => $payload,
            'qr_image_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='.urlencode($payload),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'imei' => 'nullable|string|max:64',
            'sim_number' => 'nullable|string|max:32',
            'model' => 'nullable|string|max:255',
            'android_version' => 'nullable|string|max:32',
            'fcm_token' => 'nullable|string',
            'name' => 'nullable|string|max:255',
        ]);

        $pairing = DevicePairingToken::where('token', $data['token'])->first();

        if (! $pairing || ! $pairing->isValid()) {
            return response()->json(['message' => 'Invalid or expired pairing token'], 422);
        }

        $device = Device::create([
            'user_id' => $pairing->user_id,
            'name' => $data['name'] ?? ($data['model'] ?? 'Android Device'),
            'imei' => $data['imei'] ?? null,
            'sim_number' => $data['sim_number'] ?? null,
            'model' => $data['model'] ?? null,
            'android_version' => $data['android_version'] ?? null,
            'fcm_token' => $data['fcm_token'] ?? null,
            'status' => 'online',
            'last_sync_at' => now(),
        ]);

        $accessToken = $device->createToken('device', ['device'])->plainTextToken;
        $device->update(['api_token_hint' => substr($accessToken, -8)]);

        $pairing->update(['used_at' => now()]);

        return response()->json([
            'device' => $device->only([
                'id', 'name', 'imei', 'sim_number', 'model', 'android_version', 'status',
            ]),
            'token' => $accessToken,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $data = $request->validate([
            'fcm_token' => 'nullable|string',
            'sim_number' => 'nullable|string|max:32',
        ]);

        $device->fill(array_filter([
            'fcm_token' => $data['fcm_token'] ?? null,
            'sim_number' => $data['sim_number'] ?? null,
            'status' => 'online',
            'last_sync_at' => now(),
        ]))->save();

        $pending = $device->messages()
            ->where('direction', 'outgoing')
            ->whereIn('status', ['queued', 'sending'])
            ->where(function ($q) {
                $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'recipient', 'body', 'status']);

        return response()->json([
            'device' => $device->only(['id', 'status', 'last_sync_at']),
            'pending_messages' => $pending,
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        if ((int) $device->id !== $id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'status' => 'sometimes|in:online,offline,paused',
            'fcm_token' => 'nullable|string',
        ]);

        $device->update(array_filter([
            'status' => $data['status'] ?? $device->status,
            'fcm_token' => $data['fcm_token'] ?? $device->fcm_token,
            'last_sync_at' => now(),
        ]));

        return response()->json(['device' => $device]);
    }
}
