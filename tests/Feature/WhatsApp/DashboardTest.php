<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AutomationRule;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    public function test_all_whatsapp_sections_render(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $this->makeTemplate($account);
        WhatsAppMessage::create([
            'user_id' => $user->id, 'whatsapp_account_id' => $account->id, 'direction' => 'outgoing',
            'connector_type' => 'cloud_api', 'message_type' => 'text', 'recipient' => '14155550123',
            'body' => 'hi', 'status' => 'failed', 'error_code' => 'NOT_ON_WHATSAPP', 'failure_reason' => 'nope',
        ]);

        foreach (['', '/connection', '/messages', '/incoming', '/templates', '/rules', '/webhooks', '/docs'] as $path) {
            $this->actingAs($user)->get('/customer/whatsapp'.$path)->assertOk();
        }

        $this->actingAs($user)->get('/customer/whatsapp')
            ->assertSee('NOT_ON_WHATSAPP')
            ->assertDontSee('EAAG-secret-token-value');
        $this->actingAs($user)->get('/customer/whatsapp/connection')->assertDontSee('EAAG-secret-token-value');
    }

    public function test_existing_dashboard_links_to_whatsapp(): void
    {
        $user = $this->makeCustomer();

        $this->actingAs($user)->get('/customer/dashboard')->assertOk()->assertSee(route('customer.whatsapp.overview'));
    }

    public function test_guests_and_super_admin_routes_are_protected(): void
    {
        $this->get('/customer/whatsapp')->assertRedirect(route('login'));
    }

    public function test_create_and_toggle_rule(): void
    {
        $user = $this->makeCustomer();

        $this->actingAs($user)->post('/customer/whatsapp/rules', [
            'name' => 'STOP', 'channel' => 'whatsapp', 'trigger' => 'incoming', 'match_type' => 'keyword',
            'keywords' => 'STOP, unsubscribe', 'action' => 'opt_out', 'message' => 'Bye',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rule = AutomationRule::sole();
        $this->assertSame(['STOP', 'unsubscribe'], $rule->keywords);
        $this->assertSame(['message' => 'Bye'], $rule->action_config);

        $this->actingAs($user)->patch("/customer/whatsapp/rules/{$rule->id}/toggle")->assertRedirect();
        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_rule_validation_rejects_bad_combinations(): void
    {
        $user = $this->makeCustomer();

        $this->actingAs($user)->post('/customer/whatsapp/rules', [
            'name' => 'x', 'channel' => 'whatsapp', 'trigger' => 'incoming', 'match_type' => 'any', 'action' => 'sms_fallback',
        ])->assertSessionHasErrors('action');

        $this->actingAs($user)->post('/customer/whatsapp/rules', [
            'name' => 'x', 'channel' => 'whatsapp', 'trigger' => 'incoming', 'match_type' => 'any',
            'action' => 'forward_webhook', 'url' => 'http://insecure.example.com',
        ])->assertSessionHasErrors('url');

        $this->actingAs($user)->post('/customer/whatsapp/rules', [
            'name' => 'x', 'channel' => 'whatsapp', 'trigger' => 'incoming', 'match_type' => 'regex',
            'keywords' => '(broken', 'action' => 'auto_reply', 'message' => 'hi',
        ])->assertSessionHasErrors('keywords');

        $this->assertSame(0, AutomationRule::count());
    }

    public function test_cannot_touch_other_accounts_resources(): void
    {
        $owner = $this->makeCustomer();
        $intruder = $this->makeCustomer();
        $account = $this->makeCloudAccount($owner);
        $template = $this->makeTemplate($account);
        $rule = AutomationRule::create([
            'user_id' => $owner->id, 'name' => 'r', 'channel' => 'whatsapp', 'trigger' => 'incoming',
            'match_type' => 'any', 'action' => 'auto_reply', 'action_config' => ['message' => 'x'], 'is_active' => true,
        ]);

        $this->actingAs($intruder)->get("/customer/whatsapp/templates/{$template->id}/edit")->assertForbidden();
        $this->actingAs($intruder)->delete("/customer/whatsapp/templates/{$template->id}")->assertForbidden();
        $this->actingAs($intruder)->delete("/customer/whatsapp/rules/{$rule->id}")->assertForbidden();
        $this->actingAs($intruder)->delete("/customer/whatsapp/accounts/{$account->id}")->assertForbidden();
        $this->actingAs($intruder)->post("/customer/whatsapp/accounts/{$account->id}/default")->assertForbidden();

        $this->assertNull($account->fresh()->revoked_at);
    }

    public function test_revoke_from_dashboard_clears_token(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);

        $this->actingAs($user)->delete("/customer/whatsapp/accounts/{$account->id}")->assertRedirect();

        $account->refresh();
        $this->assertSame('revoked', $account->status);
        $this->assertFalse($account->hasToken());
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.account.revoked']);
    }

    public function test_dashboard_send_and_schedule(): void
    {
        $user = $this->makeCustomer();
        $this->makeDeviceAccount($user);

        $this->actingAs($user)->post('/customer/whatsapp/messages', [
            'to' => '+14155550123', 'body' => 'later', 'scheduled_at' => now()->addHour()->toDateTimeString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $message = WhatsAppMessage::sole();
        $this->assertSame('queued', $message->status);
        $this->assertNotNull($message->scheduled_at);
    }

    public function test_whatsapp_webhook_requires_https_and_whatsapp_event(): void
    {
        $user = $this->makeCustomer();

        $this->actingAs($user)->post('/customer/whatsapp/webhooks', ['event_type' => 'message.sent', 'url' => 'https://x.example.com'])
            ->assertSessionHasErrors('event_type');
        $this->actingAs($user)->post('/customer/whatsapp/webhooks', ['event_type' => 'whatsapp.message.read', 'url' => 'http://x.example.com'])
            ->assertSessionHasErrors('url');
        $this->actingAs($user)->post('/customer/whatsapp/webhooks', ['event_type' => 'whatsapp.message.read', 'url' => 'https://x.example.com'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('webhooks', ['user_id' => $user->id, 'event_type' => 'whatsapp.message.read']);
    }
}
