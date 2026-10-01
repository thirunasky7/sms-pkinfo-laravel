<?php

namespace App\Services\Automation;

use App\Jobs\CallRuleWebhookJob;
use App\Models\AutomationRule;
use App\Models\ContactOptOut;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\Messaging\MessageRouter;
use App\Services\WhatsApp\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Shared automation engine for SMS and WhatsApp. Rules run in priority
 * order; a failing action is logged and never blocks message ingestion.
 */
class RuleEngine
{
    public function __construct(private readonly MessageRouter $router)
    {
    }

    public function onWhatsAppIncoming(WhatsAppMessage $message): void
    {
        $this->run((int) $message->user_id, 'whatsapp', 'incoming', [
            'channel' => 'whatsapp',
            'from' => $message->sender,
            'to' => $message->recipient,
            'body' => $message->body,
            'message' => $message,
            'payload' => $message->toApiArray(),
        ]);
    }

    public function onWhatsAppFailed(WhatsAppMessage $message): void
    {
        // Messages created by automation do not trigger further failure rules (prevents loops).
        if (! empty($message->meta['automation_rule_id'])) {
            return;
        }

        $this->run((int) $message->user_id, 'whatsapp', 'failed', [
            'channel' => 'whatsapp',
            'from' => $message->sender,
            'to' => $message->recipient,
            'body' => $message->body,
            'message' => $message,
            'payload' => $message->toApiArray(),
        ]);
    }

    public function onSmsIncoming(Message $message): void
    {
        $this->run((int) $message->user_id, 'sms', 'incoming', [
            'channel' => 'sms',
            'from' => $message->sender,
            'to' => $message->recipient,
            'body' => $message->body,
            'message' => $message,
            'payload' => $message->toArray(),
        ]);
    }

    private function run(int $accountId, string $channel, string $trigger, array $ctx): void
    {
        $rules = AutomationRule::query()
            ->where('user_id', $accountId)
            ->where('is_active', true)
            ->where('trigger', $trigger)
            ->whereIn('channel', [$channel, 'any'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($trigger === 'incoming' && ! $rule->matches($ctx['body'])) {
                continue;
            }

            try {
                $this->execute($rule, $accountId, $ctx);
                $rule->forceFill([
                    'trigger_count' => $rule->trigger_count + 1,
                    'last_triggered_at' => now(),
                ])->saveQuietly();
            } catch (\Throwable $e) {
                Log::warning('Automation rule failed', [
                    'rule_id' => $rule->id,
                    'action' => $rule->action,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($rule->stop_processing) {
                break;
            }
        }
    }

    private function execute(AutomationRule $rule, int $accountId, array $ctx): void
    {
        $config = $rule->action_config ?? [];
        $meta = ['automation_rule_id' => $rule->id];

        match ($rule->action) {
            'auto_reply' => $this->reply($accountId, $ctx, (string) ($config['message'] ?? ''), $meta),
            'forward_webhook' => CallRuleWebhookJob::dispatch($rule->id, [
                'event' => 'automation.rule_triggered',
                'rule' => ['id' => $rule->id, 'name' => $rule->name, 'trigger' => $rule->trigger],
                'channel' => $ctx['channel'],
                'message' => $ctx['payload'],
            ]),
            'forward_whatsapp' => $this->router->send(MessageRouter::CHANNEL_WHATSAPP, $accountId, [
                'to' => $config['to'] ?? '',
                'body' => $this->forwardText($ctx),
                'account_id' => $config['account_id'] ?? null,
                'meta' => $meta,
            ]),
            'forward_sms' => $this->router->send(MessageRouter::CHANNEL_SMS, $accountId, [
                'to' => $config['to'] ?? '',
                'body' => $this->forwardText($ctx),
            ]),
            'opt_out' => $this->optOut($accountId, $ctx, $config, $meta),
            'opt_in' => ContactOptOut::query()
                ->where('user_id', $accountId)
                ->where('phone', $this->phone($ctx['from']))
                ->delete(),
            'sms_fallback' => $this->router->send(MessageRouter::CHANNEL_SMS, $accountId, [
                'to' => '+'.ltrim((string) $ctx['to'], '+'),
                'body' => (string) ($config['message'] ?? $ctx['body'] ?? ''),
            ]),
            'route_whatsapp' => $this->routeToOtherAccount($accountId, $ctx['message'], $config, $meta),
            default => null,
        };
    }

    private function reply(int $accountId, array $ctx, string $text, array $meta): void
    {
        if ($text === '' || ! $ctx['from']) {
            return;
        }

        if ($ctx['channel'] === 'whatsapp') {
            /** @var WhatsAppMessage $incoming */
            $incoming = $ctx['message'];
            $this->sendWhatsApp($accountId, [
                'to' => $ctx['from'],
                'body' => $text,
                'account_id' => $incoming->whatsapp_account_id,
                'meta' => $meta,
            ]);

            return;
        }

        $this->router->send(MessageRouter::CHANNEL_SMS, $accountId, [
            'to' => $ctx['from'],
            'body' => $text,
            'device_id' => $ctx['message']->device_id,
        ]);
    }

    private function optOut(int $accountId, array $ctx, array $config, array $meta): void
    {
        $phone = $this->phone($ctx['from']);
        if (! $phone) {
            return;
        }

        $scope = in_array($config['scope'] ?? null, ['whatsapp', 'sms', 'all'], true) ? $config['scope'] : $ctx['channel'];
        $alreadyOut = ContactOptOut::isOptedOut($accountId, $ctx['channel'], $phone);

        // The confirmation must go out before the opt-out blocks further sends.
        if (! empty($config['message']) && ! $alreadyOut) {
            if ($ctx['channel'] === 'whatsapp') {
                $this->sendWhatsApp($accountId, [
                    'to' => $ctx['from'],
                    'body' => $config['message'],
                    'account_id' => $ctx['message']->whatsapp_account_id,
                    'meta' => $meta,
                ]);
            } elseif ($ctx['channel'] === 'sms') {
                $this->router->send(MessageRouter::CHANNEL_SMS, $accountId, [
                    'to' => $ctx['from'], 'body' => $config['message'], 'device_id' => $ctx['message']->device_id,
                ]);
            }
        }

        ContactOptOut::query()->firstOrCreate(
            ['user_id' => $accountId, 'channel' => $scope, 'phone' => $phone],
            ['source' => 'automation', 'reason' => mb_substr((string) $ctx['body'], 0, 255)]
        );
    }

    private function routeToOtherAccount(int $accountId, WhatsAppMessage $failed, array $config, array $meta): void
    {
        $target = WhatsAppAccount::query()
            ->forAccount($accountId)
            ->usable()
            ->where('id', '!=', $failed->whatsapp_account_id)
            ->when($config['account_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when($config['connector'] ?? null, fn ($q, $c) => $q->where('connector_type', $c))
            ->orderByDesc('is_default')
            ->first();

        if (! $target) {
            return;
        }

        $this->sendWhatsApp($accountId, [
            'account_id' => $target->id,
            'to' => $failed->recipient,
            'message_type' => $failed->message_type === 'template' ? 'text' : $failed->message_type,
            'body' => $failed->body,
            'media_url' => $failed->media_url,
            'media_mime' => $failed->media_mime,
            'media_filename' => $failed->media_filename,
            'external_id' => $failed->external_id,
            'meta' => $meta + ['rerouted_from' => $failed->message_id],
        ]);
    }

    /**
     * All automated WhatsApp sends go through the router so quota and account checks apply.
     */
    private function sendWhatsApp(int $accountId, array $payload): void
    {
        $this->router->send(MessageRouter::CHANNEL_WHATSAPP, $accountId, $payload);
    }

    private function forwardText(array $ctx): string
    {
        $label = $ctx['channel'] === 'whatsapp' ? 'WhatsApp' : 'SMS';

        return mb_substr("[{$label} from {$ctx['from']}] ".(string) $ctx['body'], 0, 1500);
    }

    private function phone(?string $raw): ?string
    {
        return PhoneNumber::normalize($raw) ?? ($raw ? mb_substr($raw, 0, 32) : null);
    }
}
