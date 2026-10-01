<?php

namespace App\Console\Commands;

use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\TemplateService;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Console\Command;

class SyncWhatsAppTemplates extends Command
{
    protected $signature = 'whatsapp:sync-templates';

    protected $description = 'Pull template status from Meta for all Cloud API accounts';

    public function handle(TemplateService $templates): int
    {
        WhatsAppAccount::query()
            ->where('connector_type', WhatsAppAccount::CONNECTOR_CLOUD_API)
            ->usable()
            ->each(function (WhatsAppAccount $account) use ($templates) {
                try {
                    $r = $templates->sync($account);
                    $this->line("Account {$account->id}: {$r['synced']} synced, {$r['disabled']} disabled");
                } catch (WhatsAppException $e) {
                    $this->warn("Account {$account->id}: {$e->errorCode} {$e->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
