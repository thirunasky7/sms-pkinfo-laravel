<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeviceConnectorTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    public function test_device_connects_whatsapp_and_becomes_default(): void
    {
        $user = $this->makeCustomer();
        [$device, $headers] = $this->makeDeviceWithToken($user);

        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/connect", [
            'phone_number' => '+91 98000 00001',
            'package' => 'com.whatsapp',
        ], $headers)
            ->assertStatus(201)
            ->assertJsonPath('account.connector_type', 'device')
            ->assertJsonPath('account.status', 'connected')
            ->assertJsonPath('account.phone_number', '919800000001')
            ->assertJsonPath('account.is_default', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.device.connected', 'admin_id' => $user->id]);
    }

    public function test_device_cannot_act_for_another_device(): void
    {
        $user = $this->makeCustomer();
        [, $headers] = $this->makeDeviceWithToken($user);
        $other = $this->makeDevice($user);

        $this->postJson("/api/v1/devices/{$other->id}/whatsapp/connect", ['phone_number' => '919800000001'], $headers)
            ->assertStatus(403);
    }

    public function test_full_send_sync_status_flow_with_webhooks(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        [, $apiHeaders] = $this->makeApiKey($user, ['whatsapp:send', 'whatsapp:read']);
        foreach (['sent', 'delivered', 'read'] as $e) {
            Webhook::create(['user_id' => $user->id, 'event_type' => "whatsapp.message.$e", 'url' => "https://crm.test/$e", 'secret' => 's', 'is_active' => true]);
        }

        $messageId = $this->postJson('/api/v1/whatsapp/send', ['to' => '+1 555 123 4567', 'body' => 'Hello'], $apiHeaders)
            ->assertStatus(202)
            ->assertJsonPath('message.status', 'queued')
            ->assertJsonPath('message.connector_type', 'device')
            ->assertJsonPath('message.recipient', '15551234567')
            ->json('message.message_id');

        $this->assertSame(1, $user->activeSubscription()->first()->whatsapp_used);

        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/sync", [], $deviceHeaders)
            ->assertOk()
            ->assertJsonCount(1, 'pending_messages')
            ->assertJsonPath('pending_messages.0.message_id', $messageId)
            ->assertJsonPath('pending_messages.0.to', '15551234567');

        // A second sync must not hand out the same message again.
        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/sync", [], $deviceHeaders)
            ->assertJsonCount(0, 'pending_messages');

        foreach (['sent', 'delivered', 'read'] as $status) {
            $this->postJson("/api/v1/devices/{$device->id}/whatsapp/messages/{$messageId}/status", ['status' => $status], $deviceHeaders)
                ->assertOk()
                ->assertJsonPath('message.status', $status);
        }

        // Late "delivered" must not move a read message backwards.
        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/messages/{$messageId}/status", ['status' => 'delivered'], $deviceHeaders)
            ->assertJsonPath('message.status', 'read');

        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->url() === 'https://crm.test/read'
            && $r->hasHeader('X-SMS-Gateway-Event', 'whatsapp.message.read')
            && $r['message']['message_id'] === $messageId);

        $this->getJson("/api/v1/whatsapp/message/{$messageId}", $apiHeaders)
            ->assertOk()
            ->assertJsonPath('message.status', 'read')
            ->assertJsonPath('message.device_id', $device->id);
    }

    public function test_send_media_by_url(): void
    {
        $user = $this->makeCustomer();
        $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', [
            'to' => '15551234567',
            'type' => 'image',
            'media_url' => 'https://cdn.example.com/p/photo.jpg',
            'body' => 'caption',
        ], $headers)
            ->assertStatus(202)
            ->assertJsonPath('message.message_type', 'image')
            ->assertJsonPath('message.media_url', 'https://cdn.example.com/p/photo.jpg');
    }

    public function test_media_requires_https_url(): void
    {
        $user = $this->makeCustomer();
        $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', [
            'to' => '15551234567', 'type' => 'image', 'media_url' => 'http://insecure.example.com/a.jpg',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('media_url');
    }

    public function test_invalid_number_is_rejected(): void
    {
        $user = $this->makeCustomer();
        $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', ['to' => 'abc123', 'body' => 'x'], $headers)
            ->assertStatus(422)
            ->assertJson(['error_code' => 'INVALID_NUMBER']);

        $this->postJson('/api/v1/whatsapp/send', ['to' => '0123', 'body' => 'x'], $headers)
            ->assertStatus(422)
            ->assertJson(['error_code' => 'INVALID_NUMBER']);
    }

    public function test_known_non_whatsapp_number_is_rejected_and_reported_by_check_number(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);

        WhatsAppMessage::create([
            'user_id' => $user->id, 'whatsapp_account_id' => $account->id, 'direction' => 'outgoing',
            'connector_type' => 'device', 'recipient' => '15550009999', 'status' => 'failed', 'error_code' => 'NOT_ON_WHATSAPP',
        ]);

        $this->postJson('/api/v1/whatsapp/check-number', ['phone' => '+1 555 000 9999'], $headers)
            ->assertOk()
            ->assertJson(['valid' => true, 'on_whatsapp' => false, 'status' => 'not_on_whatsapp']);

        $this->postJson('/api/v1/whatsapp/send', ['to' => '15550009999', 'body' => 'x'], $headers)
            ->assertStatus(422)
            ->assertJson(['error_code' => 'NOT_ON_WHATSAPP']);
    }

    public function test_check_number_invalid_and_unknown(): void
    {
        $user = $this->makeCustomer();
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/check-number', ['phone' => 'not-a-number'], $headers)
            ->assertStatus(422)
            ->assertJson(['error_code' => 'INVALID_NUMBER', 'valid' => false]);

        $this->postJson('/api/v1/whatsapp/check-number', ['phone' => '447700900123'], $headers)
            ->assertOk()
            ->assertJson(['status' => 'unknown', 'on_whatsapp' => null]);
    }

    public function test_duplicate_request_with_idempotency_key_returns_original(): void
    {
        $user = $this->makeCustomer();
        $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);
        $headers['Idempotency-Key'] = 'order-42';

        $first = $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)
            ->assertStatus(202)->json('message.message_id');

        $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)
            ->assertStatus(200)
            ->assertJson(['duplicate' => true])
            ->assertJsonPath('message.message_id', $first);

        $this->assertSame(1, WhatsAppMessage::count());
        $this->assertSame(1, $user->activeSubscription()->first()->whatsapp_used);
    }

    public function test_no_account_returns_409(): void
    {
        $user = $this->makeCustomer();
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)
            ->assertStatus(409)
            ->assertJson(['error_code' => 'NO_WHATSAPP_ACCOUNT']);
    }

    public function test_quota_exceeded_returns_402(): void
    {
        $user = $this->makeCustomer(['whatsapp_limit' => 1], ['whatsapp_used' => 1]);
        $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)
            ->assertStatus(402);
    }

    public function test_rate_limit_is_enforced_per_api_key(): void
    {
        $user = $this->makeCustomer();
        $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user, null, 2);

        $this->postJson('/api/v1/whatsapp/check-number', ['phone' => '15551234567'], $headers)->assertOk();
        $this->postJson('/api/v1/whatsapp/check-number', ['phone' => '15551234567'], $headers)->assertOk();
        $this->postJson('/api/v1/whatsapp/check-number', ['phone' => '15551234567'], $headers)
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_device_failure_retries_then_fails_permanently(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        [, $apiHeaders] = $this->makeApiKey($user);
        config(['whatsapp.max_retries' => 2]);

        $id = $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $apiHeaders)->json('message.message_id');

        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/sync", [], $deviceHeaders)->assertJsonCount(1, 'pending_messages');
        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/messages/{$id}/status", [
            'status' => 'failed', 'error_code' => 'UI_TIMEOUT', 'error' => 'Send button not found',
        ], $deviceHeaders)
            ->assertJsonPath('message.status', 'queued')
            ->assertJsonPath('message.retry_count', 1)
            ->assertJsonPath('message.error_code', 'UI_TIMEOUT');

        // Backoff: not handed out again until scheduled_at passes.
        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/sync", [], $deviceHeaders)->assertJsonCount(0, 'pending_messages');
        $this->travel(5)->minutes();
        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/sync", [], $deviceHeaders)->assertJsonCount(1, 'pending_messages');

        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/messages/{$id}/status", ['status' => 'failed'], $deviceHeaders)
            ->assertJsonPath('message.status', 'failed')
            ->assertJsonPath('message.retry_count', 2);
    }

    public function test_incoming_message_is_recorded_once_and_emits_webhook(): void
    {
        Queue::fake([DispatchWebhookJob::class]);
        $user = $this->makeCustomer();
        [$device, $headers] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);

        $payload = ['sender' => '+44 7700 900123', 'sender_name' => 'Alice', 'body' => 'Hi there', 'client_ref' => 'n-1'];

        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/messages/incoming", $payload, $headers)
            ->assertStatus(201)
            ->assertJsonPath('message.direction', 'incoming')
            ->assertJsonPath('message.sender', '447700900123')
            ->assertJsonPath('message.status', 'received');

        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/messages/incoming", $payload, $headers)
            ->assertStatus(200)
            ->assertJson(['duplicate' => true]);

        $this->assertSame(1, WhatsAppMessage::where('direction', 'incoming')->count());
        Queue::assertPushed(DispatchWebhookJob::class, fn ($job) => $job->eventType === 'whatsapp.message.received');
    }

    public function test_monitor_marks_silent_device_disconnected_and_fails_stale_messages(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $id = $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)->json('message.message_id');

        $this->travel(10)->minutes();
        $this->artisan('whatsapp:monitor-devices')->assertSuccessful();
        $this->assertSame(WhatsAppAccount::STATUS_DISCONNECTED, $account->fresh()->status);

        // Still accepted while disconnected, with a warning.
        $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234568', 'body' => 'y'], $headers)
            ->assertStatus(202)
            ->assertJsonStructure(['warning']);

        $this->travel(2)->hours();
        $this->artisan('whatsapp:monitor-devices')->assertSuccessful();

        $message = WhatsAppMessage::where('message_id', $id)->first();
        $this->assertSame('failed', $message->status);
        $this->assertSame('DEVICE_DISCONNECTED', $message->error_code);
    }

    public function test_messages_list_is_isolated_and_filterable(): void
    {
        $user = $this->makeCustomer();
        $other = $this->makeCustomer();
        $account = $this->makeDeviceAccount($user);
        $otherAccount = $this->makeDeviceAccount($other);
        [, $headers] = $this->makeApiKey($user);

        foreach ([[$user, $account, 'outgoing'], [$user, $account, 'incoming'], [$other, $otherAccount, 'outgoing']] as [$u, $a, $dir]) {
            WhatsAppMessage::create([
                'user_id' => $u->id, 'whatsapp_account_id' => $a->id, 'direction' => $dir,
                'connector_type' => 'device', 'recipient' => '15551234567', 'status' => $dir === 'incoming' ? 'received' : 'sent',
            ]);
        }

        $this->getJson('/api/v1/whatsapp/messages', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/whatsapp/messages?direction=incoming', $headers)->assertJsonCount(1, 'data');

        $foreignId = WhatsAppMessage::where('user_id', $other->id)->value('message_id');
        $this->getJson("/api/v1/whatsapp/message/{$foreignId}", $headers)->assertStatus(404);
    }

    public function test_revoking_account_fails_pending_messages(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeDeviceAccount($user);
        [, $headers] = $this->makeApiKey($user);
        $id = $this->postJson('/api/v1/whatsapp/send', ['to' => '15551234567', 'body' => 'x'], $headers)->json('message.message_id');

        $token = $user->createToken('t')->plainTextToken;
        $this->deleteJson("/api/v1/whatsapp/accounts/{$account->id}", [], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertNotNull($account->fresh()->revoked_at);
        $this->assertSame('ACCOUNT_REVOKED', WhatsAppMessage::where('message_id', $id)->value('error_code'));
        $this->getJson('/api/v1/whatsapp/connection', $headers)->assertJson(['status' => 'not_configured']);
    }
}
