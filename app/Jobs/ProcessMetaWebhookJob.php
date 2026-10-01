<?php

namespace App\Jobs;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\Meta\MetaErrorMapper;
use App\Services\WhatsApp\Meta\MetaStatusMapper;
use App\Services\WhatsApp\WhatsAppMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ProcessMetaWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public array $payload)
    {
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(WhatsAppMessageService $messages, MetaStatusMapper $statuses, MetaErrorMapper $errors): void
    {
        foreach ($this->payload['entry'] ?? [] as $entry) {
            $wabaId = (string) ($entry['id'] ?? '');

            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                match ($change['field'] ?? null) {
                    'messages' => $this->handleMessages($value, $messages, $statuses, $errors),
                    'message_template_status_update' => $this->handleTemplateStatus($wabaId, $value, $statuses),
                    'account_update', 'phone_number_quality_update', 'phone_number_name_update' => $this->handleAccountEvent($wabaId, $change['field'], $value),
                    default => null,
                };
            }
        }
    }

    private function handleMessages(array $value, WhatsAppMessageService $messages, MetaStatusMapper $statuses, MetaErrorMapper $errors): void
    {
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $account = WhatsAppAccount::query()
            ->where('connector_type', WhatsAppAccount::CONNECTOR_CLOUD_API)
            ->where('phone_number_id', $phoneNumberId)
            ->whereNull('revoked_at')
            ->first();

        if (! $account) {
            return;
        }

        foreach ($value['statuses'] ?? [] as $status) {
            $message = WhatsAppMessage::query()
                ->where('whatsapp_account_id', $account->id)
                ->where('provider_message_id', $status['id'] ?? '')
                ->first();

            $mapped = $statuses->map((string) ($status['status'] ?? ''));
            if (! $message || ! $mapped) {
                continue;
            }

            $at = isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : null;

            if ($mapped === WhatsAppMessage::STATUS_FAILED) {
                $error = $status['errors'][0] ?? [];
                $info = $errors->map((int) ($error['code'] ?? 0), $error['error_data']['details'] ?? $error['title'] ?? $error['message'] ?? null, 400);
                // Asynchronous failures after acceptance are not retried automatically, except throttling.
                $messages->applyFailure($message, $info['code'], $info['message'], $info['code'] === 'RATE_LIMITED');
            } else {
                $messages->applyStatus($message, $mapped, null, $at);
            }
        }

        $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);

        foreach ($value['messages'] ?? [] as $incoming) {
            $type = (string) ($incoming['type'] ?? 'text');
            [$body, $mediaId, $mime, $localType] = $this->extractContent($incoming, $type);

            $messages->recordIncoming($account, [
                'sender' => (string) ($incoming['from'] ?? ''),
                'sender_name' => $names[$incoming['from'] ?? ''] ?? null,
                'body' => $body,
                'message_type' => $localType,
                'media_mime' => $mime,
                'provider_message_id' => $incoming['id'] ?? null,
                'received_at' => isset($incoming['timestamp']) ? Carbon::createFromTimestamp((int) $incoming['timestamp']) : null,
                'meta' => array_filter([
                    'meta_type' => $type,
                    'media_id' => $mediaId,
                    'context_message_id' => $incoming['context']['id'] ?? null,
                ]),
            ]);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: string}
     */
    private function extractContent(array $m, string $type): array
    {
        return match ($type) {
            'text' => [$m['text']['body'] ?? null, null, null, 'text'],
            'image', 'video', 'audio', 'document', 'sticker' => [
                $m[$type]['caption'] ?? ($m[$type]['filename'] ?? null),
                $m[$type]['id'] ?? null,
                $m[$type]['mime_type'] ?? null,
                $type === 'sticker' ? 'image' : $type,
            ],
            'button' => [$m['button']['text'] ?? null, null, null, 'text'],
            'interactive' => [
                $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? null,
                null, null, 'text',
            ],
            'location' => [
                trim(($m['location']['name'] ?? '').' '.($m['location']['latitude'] ?? '').','.($m['location']['longitude'] ?? '')),
                null, null, 'text',
            ],
            default => ['['.$type.' message]', null, null, 'text'],
        };
    }

    private function handleTemplateStatus(string $wabaId, array $value, MetaStatusMapper $statuses): void
    {
        $template = WhatsAppTemplate::query()
            ->where(function ($q) use ($value, $wabaId) {
                $q->where('provider_template_id', (string) ($value['message_template_id'] ?? ''))
                    ->orWhere(fn ($q) => $q
                        ->where('name', $value['message_template_name'] ?? '')
                        ->where('language', $value['message_template_language'] ?? '')
                        ->whereHas('account', fn ($a) => $a->where('waba_id', $wabaId)));
            })
            ->first();

        if (! $template) {
            return;
        }

        $status = $statuses->mapTemplateStatus((string) ($value['event'] ?? ''));
        $reason = $value['reason'] ?? null;

        $template->update([
            'status' => $status,
            'rejection_reason' => $status === 'rejected' && $reason && $reason !== 'NONE' ? $reason : null,
            'provider_template_id' => $template->provider_template_id ?? (string) ($value['message_template_id'] ?? ''),
            'last_synced_at' => now(),
        ]);
    }

    private function handleAccountEvent(string $wabaId, string $field, array $value): void
    {
        WhatsAppAccount::query()
            ->where('waba_id', $wabaId)
            ->whereNull('revoked_at')
            ->each(function (WhatsAppAccount $account) use ($field, $value) {
                $meta = $account->meta ?? [];
                $meta['events'][$field] = ['value' => $value, 'at' => now()->toIso8601String()];
                if ($field === 'phone_number_quality_update' && isset($value['current_limit'])) {
                    $meta['messaging_limit'] = $value['current_limit'];
                }
                $account->update(['meta' => $meta]);
            });
    }
}
