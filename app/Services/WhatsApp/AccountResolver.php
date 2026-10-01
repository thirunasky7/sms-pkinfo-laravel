<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;

class AccountResolver
{
    /**
     * Resolve the WhatsApp account to send from. An explicit account id must
     * belong to the caller's account; otherwise the default (or most recently
     * active) usable account is chosen, optionally filtered by connector.
     */
    public function resolve(int $accountId, ?int $whatsappAccountId = null, ?string $connector = null): ?WhatsAppAccount
    {
        $query = WhatsAppAccount::query()
            ->forAccount($accountId)
            ->usable()
            ->when($connector, fn ($q) => $q->where('connector_type', $connector));

        if ($whatsappAccountId) {
            return $query->where('id', $whatsappAccountId)->first();
        }

        return $query
            ->orderByDesc('is_default')
            ->orderByRaw("CASE WHEN status = 'connected' THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->first();
    }
}
