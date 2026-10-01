<?php

namespace App\Services\WhatsApp;

use App\Models\Device;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * Link (or re-link) the WhatsApp installation on a paired device.
     */
    public function connectDevice(Device $device, array $data): WhatsAppAccount
    {
        $phone = PhoneNumber::normalize($data['phone_number'] ?? null);
        if (! $phone) {
            throw new WhatsAppException('INVALID_NUMBER', 'The WhatsApp phone number is not a valid international number');
        }

        return DB::transaction(function () use ($device, $data, $phone) {
            $account = WhatsAppAccount::query()
                ->where('user_id', $device->user_id)
                ->where('connector_type', WhatsAppAccount::CONNECTOR_DEVICE)
                ->where('device_id', $device->id)
                ->first() ?? new WhatsAppAccount([
                    'user_id' => $device->user_id,
                    'connector_type' => WhatsAppAccount::CONNECTOR_DEVICE,
                    'device_id' => $device->id,
                ]);

            $isFirst = ! WhatsAppAccount::query()->forAccount($device->user_id)->whereNull('revoked_at')->exists();

            $account->fill([
                'name' => $data['name'] ?? ($account->name ?: ($device->name ?: 'Device').' WhatsApp'),
                'phone_number' => $phone,
                'display_name' => $data['display_name'] ?? $account->display_name,
                'status' => WhatsAppAccount::STATUS_CONNECTED,
                'connected_at' => now(),
                'disconnected_at' => null,
                'revoked_at' => null,
                'last_seen_at' => now(),
                'last_error' => null,
                'is_default' => $account->exists ? $account->is_default : $isFirst,
                'meta' => array_filter([
                    'package' => $data['package'] ?? null,
                    'app_version' => $data['app_version'] ?? null,
                ]),
            ])->save();

            Audit::log((int) $device->user_id, 'whatsapp.device.connected', $account, [
                'device_id' => $device->id,
                'phone_number' => $phone,
            ]);

            return $account;
        });
    }

    public function markDisconnected(WhatsAppAccount $account, ?string $reason = null): void
    {
        if ($account->status === WhatsAppAccount::STATUS_DISCONNECTED) {
            return;
        }

        $account->update([
            'status' => WhatsAppAccount::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
            'last_error' => $reason,
        ]);
    }

    public function setDefault(WhatsAppAccount $account): void
    {
        DB::transaction(function () use ($account) {
            WhatsAppAccount::query()->forAccount($account->user_id)->update(['is_default' => false]);
            $account->update(['is_default' => true]);
        });
    }

    /**
     * Revoke credentials and stop all pending sends for the account.
     */
    public function revoke(WhatsAppAccount $account, int $actorId): void
    {
        DB::transaction(function () use ($account, $actorId) {
            $account->forceFill([
                'status' => WhatsAppAccount::STATUS_REVOKED,
                'revoked_at' => now(),
                'access_token' => null,
                'token_expires_at' => null,
                'is_default' => false,
            ])->save();

            WhatsAppMessage::query()
                ->where('whatsapp_account_id', $account->id)
                ->whereIn('status', [WhatsAppMessage::STATUS_QUEUED, WhatsAppMessage::STATUS_SENDING])
                ->update([
                    'status' => WhatsAppMessage::STATUS_FAILED,
                    'error_code' => 'ACCOUNT_REVOKED',
                    'failure_reason' => 'The WhatsApp account was revoked',
                    'failed_at' => now(),
                ]);

            Audit::log($actorId, 'whatsapp.account.revoked', $account, [
                'connector_type' => $account->connector_type,
            ]);
        });
    }
}
