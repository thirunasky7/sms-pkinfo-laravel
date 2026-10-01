<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\WhatsApp\WhatsAppTestHelpers;
use Tests\TestCase;

class ApiKeyDashboardTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    private function headers(string $key, string $secret): array
    {
        return ['X-API-Key' => $key, 'X-API-Secret' => $secret, 'Accept' => 'application/json'];
    }

    public function test_created_key_and_secret_are_shown_once_and_work(): void
    {
        $user = $this->makeCustomer();
        $this->makeDevice($user);

        $response = $this->actingAs($user)->post('/customer/api/keys', ['name' => 'Website']);
        $creds = $response->assertRedirect()->getSession()->get('new_api_credentials');

        $this->assertStringStartsWith('smk_', $creds['key']);
        $this->assertSame(40, strlen($creds['secret']));
        $this->assertNull(ApiKey::sole()->scopes);

        $this->actingAs($user)->withSession(['new_api_credentials' => $creds])->get('/customer/api')
            ->assertOk()->assertSee($creds['key'])->assertSee($creds['secret']);
        $this->actingAs($user)->get('/customer/api')
            ->assertOk()->assertSee($creds['key'])->assertDontSee($creds['secret']);

        $this->postJson('/api/v1/messages/send', ['to' => '+919800000002', 'body' => 'hi'], $this->headers($creds['key'], $creds['secret']))
            ->assertStatus(202);
    }

    public function test_regenerate_invalidates_old_secret(): void
    {
        $user = $this->makeCustomer();
        $this->makeDevice($user);
        $old = $this->actingAs($user)->post('/customer/api/keys', ['name' => 'CRM'])->getSession()->get('new_api_credentials');
        $key = ApiKey::sole();

        $new = $this->actingAs($user)->post("/customer/api/keys/{$key->id}/regenerate")
            ->assertRedirect()->getSession()->get('new_api_credentials');

        $this->assertSame($old['key'], $new['key']);
        $this->assertNotSame($old['secret'], $new['secret']);
        $this->postJson('/api/v1/messages/send', ['to' => '+919800000002', 'body' => 'x'], $this->headers($old['key'], $old['secret']))
            ->assertStatus(401);
        $this->postJson('/api/v1/messages/send', ['to' => '+919800000002', 'body' => 'x'], $this->headers($new['key'], $new['secret']))
            ->assertStatus(202);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.secret_regenerated']);
    }

    public function test_revoke_and_cross_account_protection(): void
    {
        $owner = $this->makeCustomer();
        $intruder = $this->makeCustomer();
        $creds = $this->actingAs($owner)->post('/customer/api/keys')->getSession()->get('new_api_credentials');
        $key = ApiKey::sole();

        $this->actingAs($intruder)->post("/customer/api/keys/{$key->id}/regenerate")->assertForbidden();
        $this->actingAs($intruder)->delete("/customer/api/keys/{$key->id}")->assertForbidden();
        $this->assertNull($key->fresh()->revoked_at);

        $this->actingAs($owner)->delete("/customer/api/keys/{$key->id}")->assertRedirect();
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->actingAs($owner)->post("/customer/api/keys/{$key->id}/regenerate")->assertForbidden();
        $this->postJson('/api/v1/messages/send', ['to' => '+919800000002', 'body' => 'x'], $this->headers($creds['key'], $creds['secret']))
            ->assertStatus(401);
    }

    public function test_customer_can_view_secret_later(): void
    {
        $owner = $this->makeCustomer();
        $intruder = $this->makeCustomer();
        $creds = $this->actingAs($owner)->post('/customer/api/keys', ['name' => 'Site'])->getSession()->get('new_api_credentials');
        $key = ApiKey::sole();

        $raw = \DB::table('api_keys')->where('id', $key->id)->value('secret_encrypted');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($creds['secret'], $raw);
        $this->assertArrayNotHasKey('secret_encrypted', $key->toArray());

        $this->actingAs($owner)->get('/customer/api')->assertOk()->assertSee($creds['secret']);
        $this->actingAs($owner)->get('/customer/api')->assertOk()->assertDontSee($creds['secret']);
        $this->actingAs($owner)->postJson("/customer/api/keys/{$key->id}/secret")
            ->assertOk()
            ->assertJson(['key' => $creds['key'], 'secret' => $creds['secret']])
            ->assertHeader('Cache-Control');
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.secret_viewed']);

        $this->actingAs($intruder)->postJson("/customer/api/keys/{$key->id}/secret")->assertForbidden();

        $new = $this->actingAs($owner)->post("/customer/api/keys/{$key->id}/regenerate")->getSession()->get('new_api_credentials');
        $this->actingAs($owner)->postJson("/customer/api/keys/{$key->id}/secret")->assertJson(['secret' => $new['secret']]);

        $this->actingAs($owner)->delete("/customer/api/keys/{$key->id}");
        $this->actingAs($owner)->postJson("/customer/api/keys/{$key->id}/secret")->assertNotFound();
        $this->assertNull(\DB::table('api_keys')->where('id', $key->id)->value('secret_encrypted'));
    }

    public function test_legacy_key_without_stored_secret_asks_to_regenerate(): void
    {
        $user = $this->makeCustomer();
        [$key] = $this->makeApiKey($user);

        $this->actingAs($user)->get('/customer/api')->assertOk()->assertSee('Regenerate to view');
        $this->actingAs($user)->postJson("/customer/api/keys/{$key->id}/secret")
            ->assertNotFound()
            ->assertJsonFragment(['message' => 'This key was created before secrets could be viewed. Regenerate the secret to see it.']);
    }

    public function test_access_level_limits_channels(): void
    {
        $user = $this->makeCustomer();
        $this->makeDevice($user);
        $this->makeDeviceAccount($user);

        $wa = $this->actingAs($user)->post('/customer/api/keys', ['access' => 'whatsapp'])->getSession()->get('new_api_credentials');
        $this->postJson('/api/v1/messages/send', ['to' => '+919800000002', 'body' => 'x'], $this->headers($wa['key'], $wa['secret']))
            ->assertStatus(403);
        $this->postJson('/api/v1/whatsapp/send', ['to' => '+919800000002', 'body' => 'x'], $this->headers($wa['key'], $wa['secret']))
            ->assertStatus(202);

        $sms = $this->actingAs($user)->post('/customer/api/keys', ['access' => 'sms'])->getSession()->get('new_api_credentials');
        $this->postJson('/api/v1/whatsapp/send', ['to' => '+919800000002', 'body' => 'x'], $this->headers($sms['key'], $sms['secret']))
            ->assertStatus(403);
        $this->postJson('/api/v1/messages/send', ['to' => '+919800000002', 'body' => 'x'], $this->headers($sms['key'], $sms['secret']))
            ->assertStatus(202);
    }
}
