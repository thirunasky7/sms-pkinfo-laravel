<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AutomationRule;
use App\Models\ContactOptOut;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    private function rule(User $user, array $attrs): AutomationRule
    {
        return AutomationRule::create(array_merge([
            'user_id' => $user->id,
            'name' => 'rule',
            'channel' => 'whatsapp',
            'trigger' => 'incoming',
            'match_type' => 'keyword',
            'keywords' => [],
            'action' => 'auto_reply',
            'action_config' => [],
            'priority' => 100,
            'is_active' => true,
        ], $attrs));
    }

    private function deviceIncoming(array $headers, int $deviceId, string $sender, string $body)
    {
        return $this->postJson("/api/v1/devices/{$deviceId}/whatsapp/messages/incoming", [
            'sender' => $sender,
            'body' => $body,
            'client_ref' => uniqid('', true),
        ], $headers);
    }

    public function test_stop_keyword_opts_out_after_sending_confirmation(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        $this->rule($user, [
            'keywords' => ['STOP', 'UNSUBSCRIBE'],
            'action' => 'opt_out',
            'action_config' => ['message' => 'You are unsubscribed.'],
        ]);

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'stop please')->assertSuccessful();

        $this->assertDatabaseHas('contact_opt_outs', ['user_id' => $user->id, 'channel' => 'whatsapp', 'phone' => '14155550123']);
        $this->assertDatabaseHas('whatsapp_messages', [
            'direction' => 'outgoing', 'recipient' => '14155550123', 'body' => 'You are unsubscribed.',
        ]);

        [, $headers] = $this->makeApiKey($user);
        $this->postJson('/api/v1/whatsapp/send', ['to' => '+14155550123', 'body' => 'promo'], $headers)
            ->assertStatus(422)
            ->assertJson(['error_code' => 'RECIPIENT_OPTED_OUT']);
    }

    public function test_auto_reply_only_fires_on_matching_keyword(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        $rule = $this->rule($user, ['keywords' => ['PRICE'], 'action_config' => ['message' => 'Our prices: example.com/pricing']]);

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'hello there');
        $this->assertSame(0, WhatsAppMessage::where('direction', 'outgoing')->count());

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'what is the price');
        $this->assertSame(0, WhatsAppMessage::where('direction', 'outgoing')->count(), 'first word must be the keyword');

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'Price?');
        $reply = WhatsAppMessage::where('direction', 'outgoing')->sole();
        $this->assertSame('Our prices: example.com/pricing', $reply->body);
        $this->assertSame($rule->id, $reply->meta['automation_rule_id']);
        $this->assertSame(1, $rule->fresh()->trigger_count);
    }

    public function test_contains_and_regex_matching(): void
    {
        $rule = new AutomationRule(['match_type' => 'contains', 'keywords' => ['refund']]);
        $this->assertTrue($rule->matches('I want a REFUND now'));
        $this->assertFalse($rule->matches('hello'));

        $rule = new AutomationRule(['match_type' => 'regex', 'keywords' => ['^order\s+#?\d+$']]);
        $this->assertTrue($rule->matches('order #123'));
        $this->assertFalse($rule->matches('my order'));

        $broken = new AutomationRule(['match_type' => 'regex', 'keywords' => ['(unclosed']]);
        $this->assertFalse($broken->matches('anything'));
    }

    public function test_stop_processing_and_priority(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        $this->rule($user, ['priority' => 2, 'match_type' => 'any', 'action_config' => ['message' => 'second']]);
        $this->rule($user, ['priority' => 1, 'match_type' => 'any', 'action_config' => ['message' => 'first'], 'stop_processing' => true]);

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'hi');

        $this->assertSame(['first'], WhatsAppMessage::where('direction', 'outgoing')->pluck('body')->all());
    }

    public function test_forward_webhook_posts_signed_payload(): void
    {
        Http::fake(['crm.example.com/*' => Http::response(['ok' => true])]);
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        $this->rule($user, [
            'match_type' => 'any',
            'action' => 'forward_webhook',
            'action_config' => ['url' => 'https://crm.example.com/hook', 'secret' => 'rule-secret'],
        ]);

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'new lead');

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'https://crm.example.com/hook'
                && $request->header('X-SMS-Gateway-Signature')[0] === hash_hmac('sha256', $request->body(), 'rule-secret')
                && $request['event'] === 'automation.rule_triggered'
                && $request['message']['body'] === 'new lead';
        });
    }

    public function test_forward_incoming_whatsapp_to_sms(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        $this->rule($user, ['match_type' => 'any', 'action' => 'forward_sms', 'action_config' => ['to' => '+919811111111']]);

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'need help');

        $sms = Message::where('direction', 'outgoing')->sole();
        $this->assertSame('+919811111111', $sms->recipient);
        $this->assertStringContainsString('need help', $sms->body);
    }

    public function test_failed_whatsapp_falls_back_to_sms(): void
    {
        $user = $this->makeCustomer();
        $this->makeDevice($user);
        $account = $this->makeCloudAccount($user);
        $this->rule($user, ['trigger' => 'failed', 'match_type' => 'any', 'action' => 'sms_fallback']);

        $message = $this->failedMessage($account, 'Your OTP is 1234');

        $sms = Message::where('direction', 'outgoing')->sole();
        $this->assertSame('+14155550123', $sms->recipient);
        $this->assertSame('Your OTP is 1234', $sms->body);
        $this->assertSame('failed', $message->fresh()->status);
    }

    public function test_failed_message_rerouted_to_other_account_without_loops(): void
    {
        $user = $this->makeCustomer();
        $cloud = $this->makeCloudAccount($user);
        $deviceAccount = $this->makeDeviceAccount($user);
        $this->rule($user, ['trigger' => 'failed', 'match_type' => 'any', 'action' => 'route_whatsapp']);

        $this->failedMessage($cloud, 'hello');

        $rerouted = WhatsAppMessage::where('whatsapp_account_id', $deviceAccount->id)->sole();
        $this->assertSame('hello', $rerouted->body);

        // The rerouted message failing must not trigger another reroute.
        app(WhatsAppMessageService::class)->applyFailure($rerouted, 'DEVICE_DISCONNECTED', 'offline', false);
        $this->assertSame(2, WhatsAppMessage::count());
    }

    public function test_sms_incoming_rules_run_without_changing_sms_response(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->rule($user, ['channel' => 'sms', 'keywords' => ['HELP'], 'action_config' => ['message' => 'Call 555-0100']]);

        $response = $this->postJson("/api/v1/devices/{$device->id}/messages/incoming", [
            'sender' => '+919822222222',
            'body' => 'help',
        ], $deviceHeaders);

        $response->assertSuccessful();
        $reply = Message::where('direction', 'outgoing')->sole();
        $this->assertSame('+919822222222', $reply->recipient);
        $this->assertSame('Call 555-0100', $reply->body);
    }

    public function test_rules_are_isolated_and_inactive_rules_ignored(): void
    {
        $owner = $this->makeCustomer();
        $other = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($owner);
        $this->makeDeviceAccount($owner, $device);
        $this->rule($other, ['match_type' => 'any', 'action_config' => ['message' => 'leak']]);
        $this->rule($owner, ['match_type' => 'any', 'action_config' => ['message' => 'paused'], 'is_active' => false]);

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'hi');

        $this->assertSame(0, WhatsAppMessage::where('direction', 'outgoing')->count());
    }

    public function test_failing_action_does_not_break_ingestion(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $this->makeDeviceAccount($user, $device);
        $this->rule($user, ['match_type' => 'any', 'action' => 'forward_whatsapp', 'action_config' => ['to' => 'not-a-number']]);

        $this->deviceIncoming($deviceHeaders, $device->id, '+14155550123', 'hi')->assertSuccessful();

        $this->assertSame(1, WhatsAppMessage::where('direction', 'incoming')->count());
        $this->assertSame(0, ContactOptOut::count());
    }

    private function failedMessage($account, string $body): WhatsAppMessage
    {
        $message = WhatsAppMessage::create([
            'user_id' => $account->user_id,
            'whatsapp_account_id' => $account->id,
            'direction' => 'outgoing',
            'connector_type' => $account->connector_type,
            'message_type' => 'text',
            'sender' => $account->phone_number,
            'recipient' => '14155550123',
            'body' => $body,
            'status' => 'sending',
        ]);

        app(WhatsAppMessageService::class)->applyFailure($message, 'OUTSIDE_24H_WINDOW', 'Re-engagement required', false);

        return $message;
    }
}
