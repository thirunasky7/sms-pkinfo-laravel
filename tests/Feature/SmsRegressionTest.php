<?php

namespace Tests\Feature;

use App\Jobs\PushOutgoingSmsToDeviceJob;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\WhatsApp\WhatsAppTestHelpers;
use Tests\TestCase;

/**
 * Guards the existing SMS API contract while the WhatsApp channel is added.
 */
class SmsRegressionTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    public function test_sms_send_queues_message_and_increments_usage(): void
    {
        Queue::fake();
        $user = $this->makeCustomer();
        $device = $this->makeDevice($user);
        [, $headers] = $this->makeApiKey($user, ['messages:send', 'messages:read']);

        $this->postJson('/api/v1/messages/send', ['to' => '+919812345678', 'body' => 'hello'], $headers)
            ->assertStatus(202)
            ->assertJsonPath('message.recipient', '+919812345678')
            ->assertJsonPath('message.status', 'queued')
            ->assertJsonPath('message.device_id', $device->id);

        Queue::assertPushed(PushOutgoingSmsToDeviceJob::class);
        $this->assertSame(1, $user->activeSubscription()->first()->sms_used);
    }

    public function test_sms_send_without_device_returns_422(): void
    {
        $user = $this->makeCustomer();
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/messages/send', ['to' => '123', 'body' => 'x'], $headers)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'No available device']);
    }

    public function test_sms_scheduled_message_is_not_pushed(): void
    {
        Queue::fake();
        $user = $this->makeCustomer();
        $this->makeDevice($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/messages/send', [
            'to' => '5551234', 'body' => 'later', 'scheduled_at' => now()->addHour()->toIso8601String(),
        ], $headers)->assertStatus(202);

        Queue::assertNotPushed(PushOutgoingSmsToDeviceJob::class);
    }

    public function test_sms_device_heartbeat_and_status_flow_unchanged(): void
    {
        $user = $this->makeCustomer();
        [$device, $headers] = $this->makeDeviceWithToken($user);
        $message = Message::create([
            'user_id' => $user->id, 'device_id' => $device->id, 'direction' => 'outgoing',
            'recipient' => '5551234', 'body' => 'hi', 'status' => 'queued',
        ]);

        $this->postJson('/api/v1/devices/heartbeat', [], $headers)
            ->assertOk()
            ->assertJsonPath('pending_messages.0.id', $message->id)
            ->assertJsonMissingPath('pending_whatsapp');

        $this->postJson("/api/v1/devices/{$device->id}/messages/{$message->id}/status", ['status' => 'delivered'], $headers)
            ->assertOk()
            ->assertJsonPath('message.status', 'delivered');
    }

    public function test_sms_incoming_still_recorded(): void
    {
        $user = $this->makeCustomer();
        [$device, $headers] = $this->makeDeviceWithToken($user);

        $this->postJson("/api/v1/devices/{$device->id}/messages/incoming", ['sender' => '5550000', 'body' => 'yo'], $headers)
            ->assertStatus(201)
            ->assertJsonPath('message.direction', 'incoming');
    }

    public function test_existing_webhook_events_still_accepted(): void
    {
        $user = $this->makeCustomer();
        $token = $user->createToken('t')->plainTextToken;

        $this->postJson('/api/v1/webhooks', ['event_type' => 'message.sent', 'url' => 'https://example.com/h'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);

        $this->postJson('/api/v1/webhooks', ['event_type' => 'whatsapp.message.read', 'url' => 'https://example.com/h'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);
    }
}
