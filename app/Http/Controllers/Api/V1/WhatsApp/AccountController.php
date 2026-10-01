<?php

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account management for dashboard users (Sanctum user tokens).
 */
class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = WhatsAppAccount::query()
            ->forAccount($request->user()->ownsAccountId())
            ->latest()
            ->get();

        return response()->json(['data' => $accounts->map->toPublicArray()]);
    }

    public function update(Request $request, int $id, AccountService $service): JsonResponse
    {
        $account = $this->find($request, $id);

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'is_default' => 'nullable|boolean',
        ]);

        if (isset($data['name'])) {
            $account->update(['name' => $data['name']]);
        }
        if (! empty($data['is_default'])) {
            $service->setDefault($account);
        }

        return response()->json(['account' => $account->fresh()->toPublicArray()]);
    }

    public function destroy(Request $request, int $id, AccountService $service): JsonResponse
    {
        $account = $this->find($request, $id);
        $service->revoke($account, (int) $request->user()->id);

        return response()->json(['message' => 'WhatsApp account revoked']);
    }

    private function find(Request $request, int $id): WhatsAppAccount
    {
        return WhatsAppAccount::query()
            ->forAccount($request->user()->ownsAccountId())
            ->whereNull('revoked_at')
            ->findOrFail($id);
    }
}
