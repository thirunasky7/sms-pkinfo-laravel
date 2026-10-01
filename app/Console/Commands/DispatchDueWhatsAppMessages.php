<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;

/**
 * Picks up scheduled messages and retry attempts whose time has come.
 * Device-connector messages are pulled by the phone, so only Cloud API
 * messages are pushed to the queue here.
 */
class DispatchDueWhatsAppMessages extends Command
{
    protected $signature = 'whatsapp:dispatch-due {--limit=200}';

    protected $description = 'Dispatch scheduled and retry-due WhatsApp messages';

    public function handle(): int
    {
        $count = 0;

        WhatsAppMessage::query()
            ->where('direction', 'outgoing')
            ->where('status', WhatsAppMessage::STATUS_QUEUED)
            ->where('connector_type', WhatsAppAccount::CONNECTOR_CLOUD_API)
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id')
            ->each(function ($id) use (&$count) {
                SendWhatsAppMessageJob::dispatch((int) $id);
                $count++;
            });

        $this->info("Dispatched {$count} WhatsApp messages.");

        return self::SUCCESS;
    }
}
