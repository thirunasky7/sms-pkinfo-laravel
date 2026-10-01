<?php

namespace App\Console\Commands;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\AccountService;
use App\Services\WhatsApp\WhatsAppMessageService;
use Illuminate\Console\Command;

class MonitorWhatsAppDevices extends Command
{
    protected $signature = 'whatsapp:monitor-devices';

    protected $description = 'Mark silent device connectors as disconnected and resolve messages stuck on them';

    /**
     * A device that claimed a message but never reported back within this
     * window is treated as a failed (retryable) attempt.
     */
    private const SENDING_TIMEOUT_MINUTES = 10;

    public function handle(AccountService $accounts, WhatsAppMessageService $messages): int
    {
        $offlineAfter = now()->subMinutes((int) config('whatsapp.device.offline_after_minutes', 5));

        WhatsAppAccount::query()
            ->where('connector_type', WhatsAppAccount::CONNECTOR_DEVICE)
            ->where('status', WhatsAppAccount::STATUS_CONNECTED)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $offlineAfter))
            ->each(fn (WhatsAppAccount $a) => $accounts->markDisconnected($a, 'No sync from device'));

        WhatsAppMessage::query()
            ->where('connector_type', WhatsAppAccount::CONNECTOR_DEVICE)
            ->where('status', WhatsAppMessage::STATUS_SENDING)
            ->where('last_attempt_at', '<', now()->subMinutes(self::SENDING_TIMEOUT_MINUTES))
            ->each(fn (WhatsAppMessage $m) => $messages->applyFailure(
                $m, 'DEVICE_TIMEOUT', 'The device did not confirm the send in time', true
            ));

        $failAfter = now()->subMinutes((int) config('whatsapp.device.fail_pending_after_minutes', 60));

        WhatsAppMessage::query()
            ->where('connector_type', WhatsAppAccount::CONNECTOR_DEVICE)
            ->where('status', WhatsAppMessage::STATUS_QUEUED)
            ->where('queued_at', '<', $failAfter)
            ->whereHas('account', fn ($q) => $q->where('status', WhatsAppAccount::STATUS_DISCONNECTED))
            ->each(fn (WhatsAppMessage $m) => $messages->applyFailure(
                $m, 'DEVICE_DISCONNECTED', 'The linked device stayed disconnected', false
            ));

        return self::SUCCESS;
    }
}
