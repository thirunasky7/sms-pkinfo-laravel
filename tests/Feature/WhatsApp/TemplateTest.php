<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase, WhatsAppTestHelpers;

    public function test_create_cloud_template_submits_to_meta_as_pending(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates' => Http::response(['id' => 'T1', 'status' => 'PENDING', 'category' => 'UTILITY'])]);
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user, ['whatsapp:templates']);

        $this->postJson('/api/v1/whatsapp/templates', [
            'name' => 'order_update',
            'language' => 'en_US',
            'category' => 'utility',
            'header_type' => 'text',
            'header_text' => 'Order update',
            'body' => 'Hi {{1}}, order {{2}} shipped.',
            'footer' => 'Reply STOP to opt out',
            'buttons' => [['type' => 'url', 'text' => 'Track', 'url' => 'https://shop.test/t/{{1}}', 'example' => 'https://shop.test/t/1']],
            'examples' => ['body' => ['Alex', '#123']],
        ], $headers)
            ->assertStatus(201)
            ->assertJsonPath('template.status', 'pending')
            ->assertJsonPath('template.body_variables', 2);

        Http::assertSent(function ($r) use ($account) {
            $components = collect($r['components']);

            return str_contains($r->url(), "/{$account->waba_id}/message_templates")
                && $r['category'] === 'UTILITY'
                && $components->firstWhere('type', 'BODY')['example']['body_text'] === [['Alex', '#123']]
                && $components->firstWhere('type', 'BUTTONS')['buttons'][0]['type'] === 'URL';
        });

        $this->assertSame('T1', WhatsAppTemplate::first()->provider_template_id);
    }

    public function test_cloud_template_requires_examples_for_variables(): void
    {
        Http::fake();
        $user = $this->makeCustomer();
        $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/templates', [
            'name' => 'x_tpl', 'language' => 'en', 'category' => 'marketing', 'body' => 'Hi {{1}}',
        ], $headers)->assertStatus(422)->assertJson(['error_code' => 'TEMPLATE_EXAMPLES_REQUIRED']);

        Http::assertNothingSent();
    }

    public function test_invalid_template_name_rejected(): void
    {
        $user = $this->makeCustomer();
        $this->makeCloudAccount($user);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/templates', [
            'name' => 'Bad Name!', 'language' => 'en_US', 'category' => 'utility', 'body' => 'x',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_send_unapproved_template_is_rejected(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $template = $this->makeTemplate($account, ['status' => 'pending']);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/template/send', [
            'to' => '15551234567', 'template_id' => $template->id, 'params' => ['body' => ['A', 'B']],
        ], $headers)
            ->assertStatus(422)
            ->assertJson(['error_code' => 'TEMPLATE_NOT_APPROVED', 'template_status' => 'pending']);
    }

    public function test_send_template_param_mismatch(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $template = $this->makeTemplate($account);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/template/send', [
            'to' => '15551234567', 'template_id' => $template->id, 'params' => ['body' => ['only one']],
        ], $headers)
            ->assertStatus(422)
            ->assertJson(['error_code' => 'TEMPLATE_PARAM_MISMATCH']);
    }

    public function test_send_approved_template_via_cloud(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]])]);
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $template = $this->makeTemplate($account);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/template/send', [
            'to' => '15551234567',
            'template_name' => 'order_update',
            'language' => 'en_US',
            'params' => ['body' => ['Alex', '#123']],
        ], $headers)
            ->assertStatus(202)
            ->assertJsonPath('message.message_type', 'template')
            ->assertJsonPath('message.body', 'Hi Alex, your order #123 is on its way.')
            ->assertJsonPath('message.template.name', 'order_update');

        Http::assertSent(fn ($r) => $r['type'] === 'template'
            && $r['template']['name'] === 'order_update'
            && $r['template']['language']['code'] === 'en_US'
            && $r['template']['components'][0]['parameters'][1]['text'] === '#123');

        $this->assertSame(1, $template->fresh()->usage_count);
        $this->assertSame('sent', WhatsAppMessage::first()->status);
    }

    public function test_device_template_is_local_and_sent_as_rendered_text(): void
    {
        $user = $this->makeCustomer();
        [$device, $deviceHeaders] = $this->makeDeviceWithToken($user);
        $account = $this->makeDeviceAccount($user, $device);
        [, $headers] = $this->makeApiKey($user);

        $id = $this->postJson('/api/v1/whatsapp/templates', [
            'name' => 'welcome', 'language' => 'en', 'category' => 'utility', 'body' => 'Welcome {{1}}!',
        ], $headers)->assertStatus(201)->assertJsonPath('template.status', 'approved')->json('template.id');

        $this->postJson('/api/v1/whatsapp/template/send', [
            'to' => '15551234567', 'template_id' => $id, 'params' => ['body' => ['Sam']],
        ], $headers)->assertStatus(202);

        $this->postJson("/api/v1/devices/{$device->id}/whatsapp/sync", [], $deviceHeaders)
            ->assertJsonPath('pending_messages.0.body', 'Welcome Sam!')
            ->assertJsonPath('pending_messages.0.type', 'template');
    }

    public function test_sync_upserts_and_disables_missing(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $stale = $this->makeTemplate($account, ['name' => 'old_one', 'provider_template_id' => 'OLD']);
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [
                ['id' => 'R1', 'name' => 'promo', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Sale {{1}}'], ['type' => 'FOOTER', 'text' => 'Bye']]],
                ['id' => 'R2', 'name' => 'denied', 'language' => 'en_US', 'status' => 'REJECTED', 'category' => 'UTILITY',
                    'rejected_reason' => 'INVALID_FORMAT', 'components' => [['type' => 'BODY', 'text' => 'x']]],
            ],
        ])]);
        [, $headers] = $this->makeApiKey($user);

        $this->postJson('/api/v1/whatsapp/templates/sync', [], $headers)
            ->assertOk()
            ->assertJson(['synced' => 2, 'disabled' => 1]);

        $this->assertSame('approved', WhatsAppTemplate::where('name', 'promo')->value('status'));
        $this->assertSame('INVALID_FORMAT', WhatsAppTemplate::where('name', 'denied')->value('rejection_reason'));
        $this->assertSame('disabled', $stale->fresh()->status);
    }

    public function test_template_status_webhook_updates_template(): void
    {
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $template = $this->makeTemplate($account, ['status' => 'pending', 'provider_template_id' => '777']);
        $payload = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => $account->waba_id, 'changes' => [[
                'field' => 'message_template_status_update',
                'value' => ['event' => 'REJECTED', 'message_template_id' => 777, 'message_template_name' => 'order_update',
                    'message_template_language' => 'en_US', 'reason' => 'ABUSIVE_CONTENT'],
            ]]]],
        ]);

        $this->call('POST', '/api/v1/whatsapp/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'test-app-secret'),
        ], $payload)->assertOk();

        $this->assertSame('rejected', $template->fresh()->status);
        $this->assertSame('ABUSIVE_CONTENT', $template->fresh()->rejection_reason);
    }

    public function test_delete_cloud_template_calls_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $user = $this->makeCustomer();
        $account = $this->makeCloudAccount($user);
        $template = $this->makeTemplate($account, ['provider_template_id' => '55']);
        [, $headers] = $this->makeApiKey($user);

        $this->deleteJson("/api/v1/whatsapp/templates/{$template->id}", [], $headers)->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'name=order_update') && str_contains($r->url(), 'hsm_id=55'));
        $this->assertDatabaseMissing('whatsapp_templates', ['id' => $template->id]);
    }

    public function test_templates_are_isolated_between_accounts(): void
    {
        $user = $this->makeCustomer();
        $other = $this->makeCustomer();
        $foreign = $this->makeTemplate($this->makeCloudAccount($other));
        [, $headers] = $this->makeApiKey($user);

        $this->getJson("/api/v1/whatsapp/templates/{$foreign->id}", $headers)->assertStatus(404);
        $this->postJson('/api/v1/whatsapp/template/send', [
            'to' => '15551234567', 'template_id' => $foreign->id, 'params' => ['body' => ['a', 'b']],
        ], $headers)->assertStatus(404);
    }
}
