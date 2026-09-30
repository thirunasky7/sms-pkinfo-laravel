<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'rate_limit' => 'nullable|integer|min:1|max:10000',
        ]);

        $secret = Str::random(40);
        $key = 'smk_'.Str::random(24);

        $apiKey = ApiKey::create([
            'user_id' => $request->user()->ownsAccountId(),
            'name' => $data['name'] ?? 'Default',
            'key' => $key,
            'secret_hash' => Hash::make($secret),
            'rate_limit' => $data['rate_limit'] ?? 60,
            'scopes' => ['messages:send', 'messages:read'],
        ]);

        return response()->json([
            'api_key' => $apiKey,
            'secret' => $secret,
            'warning' => 'Store the secret now; it will not be shown again.',
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $keys = ApiKey::query()
            ->where('user_id', $request->user()->ownsAccountId())
            ->latest()
            ->get();

        return response()->json(['data' => $keys]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $key = ApiKey::query()
            ->where('user_id', $request->user()->ownsAccountId())
            ->findOrFail($id);

        $key->update(['revoked_at' => now()]);

        return response()->json(['message' => 'API key revoked']);
    }
}
