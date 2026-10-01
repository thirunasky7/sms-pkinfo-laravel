<?php

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $accountId = $request->user()->ownsAccountId();

        $accounts = WhatsAppAccount::query()
            ->forAccount($accountId)
            ->whereNull('revoked_at')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        $connected = $accounts->filter->isConnected();

        return response()->json([
            'connected' => $connected->isNotEmpty(),
            'status' => $connected->isNotEmpty()
                ? 'connected'
                : ($accounts->isEmpty() ? 'not_configured' : 'disconnected'),
            'connectors' => [
                'device' => $connected->contains(fn ($a) => $a->isDevice()),
                'cloud_api' => $connected->contains(fn ($a) => $a->isCloudApi()),
            ],
            'accounts' => $accounts->map->toPublicArray()->values(),
        ]);
    }
}
