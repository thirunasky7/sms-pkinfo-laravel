<?php

namespace App\Console\Commands;

use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\Meta\CloudOnboardingService;
use Illuminate\Console\Command;

class RefreshWhatsAppTokens extends Command
{
    protected $signature = 'whatsapp:refresh-tokens';

    protected $description = 'Refresh Meta access tokens that are about to expire';

    public function handle(CloudOnboardingService $onboarding): int
    {
        $threshold = now()->addDays((int) config('whatsapp.meta.refresh_before_days', 7));
        $refreshed = 0;
        $failed = 0;

        WhatsAppAccount::query()
            ->where('connector_type', WhatsAppAccount::CONNECTOR_CLOUD_API)
            ->whereNull('revoked_at')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $threshold)
            ->each(function (WhatsAppAccount $account) use ($onboarding, &$refreshed, &$failed) {
                $onboarding->refreshToken($account) ? $refreshed++ : $failed++;
            });

        $this->info("Refreshed {$refreshed} token(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
