<?php

namespace Tests\Feature\WhatsApp;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait WhatsAppTestHelpers
{
    protected function makeCustomer(array $planAttrs = [], array $subAttrs = []): User
    {
        $user = User::factory()->create(['role' => 'customer_admin', 'status' => 'active']);

        $plan = SubscriptionPlan::create(array_merge([
            'name' => 'Test',
            'sms_limit' => 1000,
            'whatsapp_limit' => null,
            'price' => 0,
            'duration_days' => 30,
        ], $planAttrs));

        Subscription::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
            'status' => 'active',
        ], $subAttrs));

        return $user;
    }

    /**
     * @return array{0: ApiKey, 1: array<string, string>}
     */
    protected function makeApiKey(User $user, ?array $scopes = null, int $rateLimit = 60): array
    {
        $secret = Str::random(40);
        $key = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'test',
            'key' => 'smk_'.Str::random(24),
            'secret_hash' => Hash::make($secret),
            'rate_limit' => $rateLimit,
            'scopes' => $scopes,
        ]);

        return [$key, ['X-API-Key' => $key->key, 'X-API-Secret' => $secret, 'Accept' => 'application/json']];
    }

    protected function makeDevice(User $user, array $attrs = []): Device
    {
        return Device::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Phone',
            'sim_number' => '919800000001',
            'status' => 'online',
            'last_sync_at' => now(),
        ], $attrs));
    }

    /**
     * @return array{0: Device, 1: array<string, string>}
     */
    protected function makeDeviceWithToken(User $user, array $attrs = []): array
    {
        $device = $this->makeDevice($user, $attrs);
        $token = $device->createToken('device', ['device'])->plainTextToken;

        return [$device, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }

    protected function makeDeviceAccount(User $user, ?Device $device = null, array $attrs = []): WhatsAppAccount
    {
        $device ??= $this->makeDevice($user);

        return WhatsAppAccount::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Device WA',
            'connector_type' => WhatsAppAccount::CONNECTOR_DEVICE,
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'device_id' => $device->id,
            'phone_number' => '919800000001',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ], $attrs));
    }

    protected function makeCloudAccount(User $user, array $attrs = []): WhatsAppAccount
    {
        return WhatsAppAccount::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Cloud WA',
            'connector_type' => WhatsAppAccount::CONNECTOR_CLOUD_API,
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'phone_number' => '15550001111',
            'waba_id' => 'WABA'.Str::random(6),
            'phone_number_id' => 'PNID'.Str::random(8),
            'access_token' => 'EAAG-secret-token-value',
            'token_expires_at' => now()->addDays(60),
            'connected_at' => now(),
        ], $attrs));
    }

    protected function makeTemplate(WhatsAppAccount $account, array $attrs = []): WhatsAppTemplate
    {
        return WhatsAppTemplate::create(array_merge([
            'user_id' => $account->user_id,
            'whatsapp_account_id' => $account->id,
            'name' => 'order_update',
            'language' => 'en_US',
            'category' => 'utility',
            'status' => 'approved',
            'header_type' => 'none',
            'body' => 'Hi {{1}}, your order {{2}} is on its way.',
        ], $attrs));
    }
}
