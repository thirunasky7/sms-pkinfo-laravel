<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $webhooks = Webhook::query()
            ->where('user_id', $request->user()->ownsAccountId())
            ->latest()
            ->get();

        return response()->json(['data' => $webhooks]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => 'required|string|in:message.sent,message.delivered,message.failed,message.incoming',
            'url' => 'required|url|max:2048',
        ]);

        $webhook = Webhook::create([
            'user_id' => $request->user()->ownsAccountId(),
            'event_type' => $data['event_type'],
            'url' => $data['url'],
            'secret' => Str::random(32),
            'is_active' => true,
        ]);

        return response()->json([
            'webhook' => $webhook,
            'secret' => $webhook->secret,
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::query()
            ->where('user_id', $request->user()->ownsAccountId())
            ->findOrFail($id);

        $webhook->delete();

        return response()->json(['message' => 'Webhook deleted']);
    }
}
