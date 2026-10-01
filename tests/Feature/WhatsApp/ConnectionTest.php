<?php

namespace Tests\Feature\WhatsApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    public function test_requires_api_credentials(): void
    {
        $this->getJson('/api/v1/whatsapp/connection')->assertStatus(401);
    }

    public function test_rejects_invalid_api_key(): void
    {
        $this->getJson('/api/v1/whatsapp/connection', [
            'X-API-Key' => 'smk_nope',
            'X-API-Secret' => 'wrong',
        ])->assertStatus(401)->assertJson(['message' => 'Invalid API credentials']);
    }

    public function test_rejects_revoked_api_key(): void
    {
        $user = $this->makeCustomer();
        [$key, $headers] = $this->makeApiKey($user);
        $key->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/whatsapp/connection', $headers)->assertStatus(401);
    }

    public function test_key_with_only_sms_scopes_is_forbidden(): void
    {
        $user = $this->makeCustomer();
        [, $headers] = $this->makeApiKey($user, ['messages:send', 'messages:read']);

        $this->getJson('/api/v1/whatsapp/connection', $headers)
            ->assertStatus(403)
            ->assertJson(['required_scope' => 'whatsapp:read']);
    }

    public function test_not_configured_when_no_accounts(): void
    {
        $user = $this->makeCustomer();
        [, $headers] = $this->makeApiKey($user);

        $this->getJson('/api/v1/whatsapp/connection', $headers)
            ->assertOk()
            ->assertJson(['connected' => false, 'status' => 'not_configured', 'accounts' => []]);
    }

    public function test_returns_accounts_without_tokens_and_isolated_per_account(): void
    {
        $user = $this->makeCustomer();
        $other = $this->makeCustomer();
        [, $headers] = $this->makeApiKey($user, ['whatsapp:read']);

        $this->makeCloudAccount($user);
        $this->makeDeviceAccount($user);
        $this->makeCloudAccount($other);

        $response = $this->getJson('/api/v1/whatsapp/connection', $headers)
            ->assertOk()
            ->assertJson([
                'connected' => true,
                'connectors' => ['device' => true, 'cloud_api' => true],
            ])
            ->assertJsonCount(2, 'accounts');

        $this->assertStringNotContainsString('EAAG-secret-token-value', $response->getContent());
        $this->assertStringNotContainsString('access_token', $response->getContent());
        $response->assertJsonPath('accounts.0.token_configured', true);
    }

    public function test_token_is_encrypted_at_rest(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);

        $raw = \DB::table('whatsapp_accounts')->where('id', $account->id)->value('access_token');

        $this->assertNotSame('EAAG-secret-token-value', $raw);
        $this->assertSame('EAAG-secret-token-value', $account->fresh()->access_token);
    }

    public function test_https_is_enforced_when_enabled(): void
    {
        config(['whatsapp.require_https' => true]);
        $user = $this->makeCustomer();
        [, $headers] = $this->makeApiKey($user);

        $this->getJson('/api/v1/whatsapp/connection', $headers)
            ->assertStatus(403)
            ->assertJson(['message' => 'HTTPS is required']);
    }

    public function test_kill_switch_blocks_whatsapp_sends_only(): void
    {
        config(['whatsapp.enabled' => false]);
        $user = $this->makeCustomer();
        $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', ['to' => '+14155550123', 'body' => 'x'], $headers)
            ->assertStatus(503)
            ->assertJson(['error_code' => 'WHATSAPP_DISABLED']);

        $this->postJson('/api/v1/messages/send', ['to' => '+14155550123', 'body' => 'x'], $headers)
            ->assertStatus(202);
    }
}
