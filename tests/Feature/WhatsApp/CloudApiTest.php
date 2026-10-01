<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CloudApiTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    private function sign(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');
    }

    private function postWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/whatsapp/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? $this->sign($body),
        ], $body);
    }

    private function statusPayload(WhatsAppAccount $account, string $wamid, string $status, array $extra = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $account->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'statuses' => [array_merge(['id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp], $extra)],
                    ],
                ]],
            ]],
        ];
    }

    public function test_cloud_send_text_success(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', ['to' => '+1 555 123 4567', 'body' => 'Hello'], $headers)
            ->assertStatus(202)
            ->assertJsonPath('message.connector_type', 'cloud_api');

        $message = WhatsAppMessage::first();
        $this->assertSame('sent', $message->status);
        $this->assertSame('wamid.ABC', $message->provider_message_id);

        Http::assertSent(function ($request) use ($account) {
            return str_contains($request->url(), "/{$account->phone_number_id}/messages")
                && $request->hasHeader('Authorization', 'Bearer EAAG-secret-token-value')
                && $request['to'] === '15551234567'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello';
        });
    }

    public function test_cloud_send_media_payload(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.M']]], 200)]);
        $user = $this->makeCustomer();
        $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', [
            'to' => '15551234567', 'type' => 'document', 'media_url' => 'https://cdn.example.com/inv.pdf',
            'body' => 'Your invoice', 'filename' => 'invoice.pdf',
        ], $headers)->assertStatus(202);

        Http::assertSent(fn ($r) => $r['type'] === 'document'
            && $r['document']['link'] === 'https://cdn.example.com/inv.pdf'
            && $r['document']['caption'] === 'Your invoice'
            && $r['document']['filename'] === 'invoice.pdf');
    }

    public function test_meta_error_is_mapped_and_not_retried_when_permanent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Re-engagement message', 'code' => 131047, 'fbtrace_id' => 'X'],
        ], 400)]);
        $user = $this->makeCustomer();
        $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $id = $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)->json('message.message_id');

        $this->getJson("/api/v1/whatsapp/message/{$id}", $headers)
            ->assertJsonPath('message.status', 'failed')
            ->assertJsonPath('message.error_code', 'OUTSIDE_24H_WINDOW')
            ->assertJsonPath('message.retry_count', 1);
    }

    public function test_expired_token_marks_account_error_without_leaking_token(): void
    {
        Log::spy();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190],
        ], 401)]);
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $id = $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)->json('message.message_id');

        $this->assertSame('TOKEN_EXPIRED', WhatsAppMessage::where('message_id', $id)->value('error_code'));
        $this->assertSame(WhatsAppAccount::STATUS_ERROR, $account->fresh()->status);

        Log::shouldHaveReceived('warning')->withArgs(function ($msg, $ctx = []) {
            return ! str_contains(json_encode($ctx), 'EAAG-secret-token-value');
        });
    }

    public function test_locally_expired_token_fails_without_calling_meta(): void
    {
        Http::fake();
        $user = $this->makeCustomer();
        $this->makeCloudAccount($user, ['token_expires_at' => now()->subDay()]);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)->assertStatus(202);

        $this->assertSame('TOKEN_EXPIRED', WhatsAppMessage::value('error_code'));
        Http::assertNothingSent();
    }

    public function test_network_failure_is_retried_with_backoff(): void
    {
        $networkDown = true;
        Http::fake(function () use (&$networkDown) {
            if ($networkDown) {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response(['messages' => [['id' => 'wamid.R']]], 200);
        });
        $user = $this->makeCustomer();
        $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $id = $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)->json('message.message_id');

        $message = WhatsAppMessage::where('message_id', $id)->first();
        $this->assertSame('queued', $message->status);
        $this->assertSame('NETWORK_ERROR', $message->error_code);
        $this->assertSame(1, $message->retry_count);
        $this->assertTrue($message->scheduled_at->isFuture());

        $networkDown = false;
        $this->artisan('whatsapp:dispatch-due');
        $this->assertSame('queued', $message->fresh()->status, 'not due yet');

        $this->travel(1)->minutes();
        $this->artisan('whatsapp:dispatch-due');
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNull($message->fresh()->error_code);
    }

    public function test_webhook_verification_handshake(): void
    {
        $this->get('/api/v1/whatsapp/webhooks/meta?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=12345')
            ->assertOk()
            ->assertSee('12345');

        $this->get('/api/v1/whatsapp/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')
            ->assertStatus(403);
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);

        $this->postWebhook($this->statusPayload($account, 'wamid.X', 'delivered'), 'sha256=deadbeef')
            ->assertStatus(401);

        $this->postWebhook($this->statusPayload($account, 'wamid.X', 'delivered'), '')
            ->assertStatus(401);
    }

    public function test_webhook_status_updates_delivery_read_and_failed(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $message = WhatsAppMessage::create([
            'user_id' => $user->id, 'whatsapp_account_id' => $account->id, 'direction' => 'outgoing',
            'connector_type' => 'cloud_api', 'recipient' => '15551234567', 'status' => 'sent', 'provider_message_id' => 'wamid.1',
        ]);

        $this->postWebhook($this->statusPayload($account, 'wamid.1', 'delivered'))->assertOk();
        $this->assertSame('delivered', $message->fresh()->status);

        $this->postWebhook($this->statusPayload($account, 'wamid.1', 'read'))->assertOk();
        $this->assertSame('read', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->read_at);

        $failing = WhatsAppMessage::create([
            'user_id' => $user->id, 'whatsapp_account_id' => $account->id, 'direction' => 'outgoing',
            'connector_type' => 'cloud_api', 'recipient' => '15550000000', 'status' => 'sent', 'provider_message_id' => 'wamid.2',
        ]);
        $this->postWebhook($this->statusPayload($account, 'wamid.2', 'failed', [
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]))->assertOk();

        $this->assertSame('failed', $failing->fresh()->status);
        $this->assertSame('NOT_ON_WHATSAPP', $failing->fresh()->error_code);
    }

    public function test_webhook_from_other_account_phone_is_ignored(): void
    {
        $user = $this->makeCustomer();
        $other = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $otherAccount = $this->makeCloudAccount($other);
        $message = WhatsAppMessage::create([
            'user_id' => $user->id, 'whatsapp_account_id' => $account->id, 'direction' => 'outgoing',
            'connector_type' => 'cloud_api', 'recipient' => '15551234567', 'status' => 'sent', 'provider_message_id' => 'wamid.iso',
        ]);

        $this->postWebhook($this->statusPayload($otherAccount, 'wamid.iso', 'read'))->assertOk();
        $this->assertSame('sent', $message->fresh()->status);
    }

    public function test_webhook_incoming_message_is_recorded_once(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $account->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'contacts' => [['wa_id' => '447700900123', 'profile' => ['name' => 'Alice']]],
                        'messages' => [[
                            'from' => '447700900123', 'id' => 'wamid.IN1', 'timestamp' => (string) now()->timestamp,
                            'type' => 'text', 'text' => ['body' => 'Hi!'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $incoming = WhatsAppMessage::where('direction', 'incoming')->get();
        $this->assertCount(1, $incoming);
        $this->assertSame('447700900123', $incoming[0]->sender);
        $this->assertSame('Hi!', $incoming[0]->body);
        $this->assertSame('Alice', $incoming[0]->meta['sender_name']);
    }

    public function test_manual_credentials_onboarding_from_dashboard(): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]]),
            'graph.facebook.com/*/PN123?*' => Http::response(['display_phone_number' => '+1 555-000-1111', 'verified_name' => 'Acme']),
            'graph.facebook.com/*/WABA1/subscribed_apps' => Http::response(['success' => true]),
        ]);
        $user = $this->makeCustomer();

        $this->actingAs($user)->post('/customer/whatsapp/cloud/manual', [
            'waba_id' => 'WABA1',
            'phone_number_id' => 'PN123',
            'access_token' => 'EAAG-manual-token',
        ])->assertRedirect();

        $account = WhatsAppAccount::where('phone_number_id', 'PN123')->first();
        $this->assertNotNull($account);
        $this->assertSame('connected', $account->status);
        $this->assertSame('15550001111', $account->phone_number);
        $this->assertSame('Acme', $account->display_name);
        $this->assertNull($account->token_expires_at);
        $this->assertSame('EAAG-manual-token', $account->access_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.cloud.connected']);
        $this->assertDatabaseMissing('audit_logs', ['meta' => json_encode(['access_token' => 'EAAG-manual-token'])]);
    }

    public function test_embedded_signup_exchanges_code(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAG-from-code']),
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => now()->addDays(60)->timestamp]]),
            'graph.facebook.com/*/PN9?*' => Http::response(['display_phone_number' => '15559990000', 'verified_name' => 'Shop']),
            'graph.facebook.com/*/WABA9/subscribed_apps' => Http::response(['success' => true]),
        ]);
        $user = $this->makeCustomer();

        $this->actingAs($user)->postJson('/customer/whatsapp/cloud/embedded-signup', [
            'code' => 'AQB-code', 'waba_id' => 'WABA9', 'phone_number_id' => 'PN9', 'business_id' => 'BIZ',
        ])->assertOk()->assertJsonPath('account.status', 'connected')->assertJsonMissingPath('account.access_token');

        $account = WhatsAppAccount::where('phone_number_id', 'PN9')->first();
        $this->assertSame('EAAG-from-code', $account->access_token);
        $this->assertTrue($account->token_expires_at->isFuture());
    }

    public function test_phone_linked_to_other_account_is_rejected(): void
    {
        Http::fake();
        $owner = $this->makeCustomer();
        $this->makeCloudAccount($owner, ['phone_number_id' => 'PNX']);
        $intruder = $this->makeCustomer();

        $this->actingAs($intruder)->postJson('/customer/whatsapp/cloud/manual', [
            'waba_id' => 'W', 'phone_number_id' => 'PNX', 'access_token' => 'EAAG-x',
        ])->assertStatus(409)->assertJson(['error_code' => 'PHONE_ALREADY_LINKED']);
    }

    public function test_refresh_tokens_command(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAG-new', 'expires_in' => 5184000]),
        ]);
        $user = $this->makeCustomer();
        $soon = $this->makeCloudAccount($user, ['token_expires_at' => now()->addDays(2)]);
        $later = $this->makeCloudAccount($user, ['token_expires_at' => now()->addDays(50)]);

        $this->artisan('whatsapp:refresh-tokens')->assertSuccessful();

        $this->assertSame('EAAG-new', $soon->fresh()->access_token);
        $this->assertSame('EAAG-secret-token-value', $later->fresh()->access_token);
    }
}
