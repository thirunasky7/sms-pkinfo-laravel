<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsAppMessageFailed;
use App\Events\WhatsAppMessageReceived;
use App\Jobs\DispatchWebhookJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\ContactOptOut;
use App\Models\Subscription;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\Connectors\ConnectorFactory;
use App\Services\WhatsApp\Connectors\ConnectorResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsAppMessageService
{
    public function __construct(private readonly ConnectorFactory $connectors)
    {
    }

    /**
     * Validate, de-duplicate and queue an outgoing message.
     *
     * @param  array{to: string, message_type?: string, body?: ?string, media_url?: ?string, media_mime?: ?string,
     *               media_filename?: ?string, template?: ?WhatsAppTemplate, template_params?: ?array,
     *               external_id?: ?string, scheduled_at?: mixed, idempotency_key?: ?string, meta?: ?array}  $attrs
     * @param  array{api_key_id?: ?int, subscription?: ?Subscription}  $context
     * @return array{message: WhatsAppMessage, duplicate: bool}
     */
    public function queueOutgoing(WhatsAppAccount $account, array $attrs, array $context = []): array
    {
        $accountId = (int) $account->user_id;

        $to = PhoneNumber::normalize($attrs['to'] ?? null);
        if (! $to) {
            throw new WhatsAppException('INVALID_NUMBER', 'The recipient is not a valid international phone number');
        }

        $idempotencyKey = $attrs['idempotency_key'] ?? null;
        if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($accountId, $idempotencyKey))) {
            return ['message' => $existing, 'duplicate' => true];
        }

        if (! $account->isConnected() && $account->status !== WhatsAppAccount::STATUS_DISCONNECTED) {
            throw new WhatsAppException('ACCOUNT_NOT_CONNECTED', 'The WhatsApp account is not connected', 409);
        }

        if (ContactOptOut::isOptedOut($accountId, 'whatsapp', $to)) {
            throw new WhatsAppException('RECIPIENT_OPTED_OUT', 'The recipient has opted out of WhatsApp messages');
        }

        if ($this->knownWhatsAppStatus($accountId, $to) === false) {
            throw new WhatsAppException('NOT_ON_WHATSAPP', 'The recipient number is not registered on WhatsApp');
        }

        $template = $attrs['template'] ?? null;
        if ($template && ! $template->isApproved()) {
            throw new WhatsAppException('TEMPLATE_NOT_APPROVED', 'The template is not approved', 422, [
                'template_status' => $template->status,
            ]);
        }

        $scheduledAt = ! empty($attrs['scheduled_at']) ? Carbon::parse($attrs['scheduled_at']) : null;

        try {
            $message = WhatsAppMessage::create([
                'user_id' => $accountId,
                'whatsapp_account_id' => $account->id,
                'device_id' => $account->isDevice() ? $account->device_id : null,
                'api_key_id' => $context['api_key_id'] ?? null,
                'whatsapp_template_id' => $template?->id,
                'direction' => 'outgoing',
                'connector_type' => $account->connector_type,
                'message_type' => $template ? 'template' : ($attrs['message_type'] ?? 'text'),
                'sender' => $account->phone_number,
                'recipient' => $to,
                'body' => $template
                    ? $template->render($attrs['template_params']['body'] ?? [], $attrs['template_params']['header'] ?? [])
                    : ($attrs['body'] ?? null),
                'media_url' => $attrs['media_url'] ?? null,
                'media_mime' => $attrs['media_mime'] ?? null,
                'media_filename' => $attrs['media_filename'] ?? null,
                'template_params' => $template ? ($attrs['template_params'] ?? []) : null,
                'status' => WhatsAppMessage::STATUS_QUEUED,
                'external_id' => $attrs['external_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'meta' => $attrs['meta'] ?? null,
                'queued_at' => now(),
                'scheduled_at' => $scheduledAt,
            ]);
        } catch (QueryException $e) {
            // Concurrent request with the same idempotency key won the race.
            if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($accountId, $idempotencyKey))) {
                return ['message' => $existing, 'duplicate' => true];
            }
            throw $e;
        }

        $template?->increment('usage_count');
        ($context['subscription'] ?? null)?->increment('whatsapp_used');

        if (! $scheduledAt || $scheduledAt->lte(now())) {
            $this->dispatchNow($message);
        }

        return ['message' => $message->fresh(['template']), 'duplicate' => false];
    }

    /**
     * Device messages wait for the phone to pull them; Cloud API messages go through the queue.
     */
    public function dispatchNow(WhatsAppMessage $message): void
    {
        $message->loadMissing('account.device');

        if ($message->connector_type === WhatsAppAccount::CONNECTOR_DEVICE) {
            $result = $this->connectors->for($message->account)->send($message);
            if ($result->outcome === ConnectorResult::FAILED) {
                $this->applyFailure($message, $result->errorCode, $result->failureReason, $result->retryable);
            }

            return;
        }

        SendWhatsAppMessageJob::dispatch($message->id);
    }

    /**
     * Atomically move a due, queued message to "sending". Returns false if
     * another worker (or device sync) already claimed it.
     */
    public function claim(WhatsAppMessage $message): bool
    {
        $claimed = WhatsAppMessage::query()
            ->whereKey($message->id)
            ->where('status', WhatsAppMessage::STATUS_QUEUED)
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->update([
                'status' => WhatsAppMessage::STATUS_SENDING,
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $message->refresh();

            return true;
        }

        return false;
    }

    /**
     * Run a claimed message through its connector (used by the queue job).
     */
    public function attempt(WhatsAppMessage $message): void
    {
        $message->loadMissing('account.device', 'template');
        $account = $message->account;

        if (! $account || $account->revoked_at) {
            $this->applyFailure($message, 'ACCOUNT_REVOKED', 'The WhatsApp account was removed or revoked', false);

            return;
        }

        try {
            $result = $this->connectors->for($account)->send($message);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp connector exception', [
                'message_id' => $message->message_id,
                'error' => $e->getMessage(),
            ]);
            $result = ConnectorResult::failed('NETWORK_ERROR', 'Temporary connection error to the WhatsApp provider', true);
        }

        match ($result->outcome) {
            ConnectorResult::ACCEPTED => $this->applyStatus($message, WhatsAppMessage::STATUS_SENT, $result->providerMessageId),
            ConnectorResult::AWAITING_DEVICE => $message->update(['status' => WhatsAppMessage::STATUS_QUEUED]),
            default => $this->applyFailure($message, $result->errorCode, $result->failureReason, $result->retryable),
        };
    }

    /**
     * Apply a non-failure status (sent/delivered/read). Out-of-order updates are ignored.
     */
    public function applyStatus(WhatsAppMessage $message, string $status, ?string $providerMessageId = null, ?Carbon $at = null): void
    {
        if (! $message->canTransitionTo($status)) {
            return;
        }

        $at ??= now();
        $updates = ['status' => $status];

        if ($providerMessageId) {
            $updates['provider_message_id'] = $providerMessageId;
        }

        if ($message->status === WhatsAppMessage::STATUS_FAILED) {
            $updates['failed_at'] = null;
        }

        if (in_array($status, [WhatsAppMessage::STATUS_SENT, WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ], true)) {
            $updates['sent_at'] = $message->sent_at ?? $at;
            $updates['failure_reason'] = null;
            $updates['error_code'] = null;
        }
        if (in_array($status, [WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ], true)) {
            $updates['delivered_at'] = $message->delivered_at ?? $at;
        }
        if ($status === WhatsAppMessage::STATUS_READ) {
            $updates['read_at'] = $message->read_at ?? $at;
        }

        $changed = $message->status !== $status;
        $message->update($updates);

        if ($changed && in_array($status, [WhatsAppMessage::STATUS_SENT, WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ], true)) {
            $this->emit($message, 'whatsapp.message.'.$status);
        }
    }

    /**
     * Record a failed attempt; re-queue with backoff when retryable, otherwise fail permanently.
     */
    public function applyFailure(WhatsAppMessage $message, ?string $errorCode, ?string $reason, bool $retryable = true): void
    {
        if (! $message->canTransitionTo(WhatsAppMessage::STATUS_FAILED)) {
            return;
        }

        $retryCount = $message->retry_count + 1;
        $maxRetries = (int) config('whatsapp.max_retries', 3);
        $backoff = config('whatsapp.retry_backoff_seconds', [30, 120, 600]);

        $updates = [
            'retry_count' => min($retryCount, 255),
            'last_attempt_at' => $message->last_attempt_at ?? now(),
            'error_code' => $errorCode,
            'failure_reason' => $reason ? mb_substr($reason, 0, 1000) : null,
        ];

        if ($retryable && $retryCount < $maxRetries) {
            $delay = (int) ($backoff[$retryCount - 1] ?? end($backoff));
            $message->update($updates + [
                'status' => WhatsAppMessage::STATUS_QUEUED,
                'scheduled_at' => now()->addSeconds($delay),
            ]);

            return;
        }

        $message->update($updates + [
            'status' => WhatsAppMessage::STATUS_FAILED,
            'failed_at' => now(),
        ]);

        $this->emit($message, 'whatsapp.message.failed');
        event(new WhatsAppMessageFailed($message->fresh()));
    }

    /**
     * Manually re-queue a permanently failed message (dashboard "retry").
     */
    public function retry(WhatsAppMessage $message): void
    {
        if ($message->status !== WhatsAppMessage::STATUS_FAILED || $message->direction !== 'outgoing') {
            throw new WhatsAppException('NOT_RETRYABLE', 'Only failed outgoing messages can be retried', 409);
        }

        $message->update([
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'scheduled_at' => null,
            'failed_at' => null,
        ]);

        $this->dispatchNow($message);
    }

    /**
     * @param  array{sender: string, body?: ?string, message_type?: ?string, media_url?: ?string, media_mime?: ?string,
     *               sender_name?: ?string, provider_message_id?: ?string, dedupe_key?: ?string, received_at?: mixed, meta?: ?array}  $attrs
     * @return array{message: WhatsAppMessage, duplicate: bool}
     */
    public function recordIncoming(WhatsAppAccount $account, array $attrs): array
    {
        $dedupeKey = $attrs['dedupe_key'] ?? (isset($attrs['provider_message_id']) ? 'in:'.$attrs['provider_message_id'] : null);

        if ($dedupeKey && ($existing = $this->findByIdempotencyKey((int) $account->user_id, $dedupeKey))) {
            return ['message' => $existing, 'duplicate' => true];
        }

        $sender = PhoneNumber::normalize($attrs['sender']) ?? mb_substr($attrs['sender'], 0, 64);
        $receivedAt = ! empty($attrs['received_at']) ? Carbon::parse($attrs['received_at']) : now();

        try {
            $message = WhatsAppMessage::create([
                'user_id' => $account->user_id,
                'whatsapp_account_id' => $account->id,
                'device_id' => $account->isDevice() ? $account->device_id : null,
                'direction' => 'incoming',
                'connector_type' => $account->connector_type,
                'message_type' => $attrs['message_type'] ?? 'text',
                'sender' => $sender,
                'recipient' => $account->phone_number,
                'body' => $attrs['body'] ?? null,
                'media_url' => $attrs['media_url'] ?? null,
                'media_mime' => $attrs['media_mime'] ?? null,
                'provider_message_id' => $attrs['provider_message_id'] ?? null,
                'idempotency_key' => $dedupeKey,
                'status' => WhatsAppMessage::STATUS_RECEIVED,
                'meta' => array_filter(array_merge($attrs['meta'] ?? [], [
                    'sender_name' => $attrs['sender_name'] ?? null,
                ])) ?: null,
                'received_at' => $receivedAt,
            ]);
        } catch (QueryException $e) {
            if ($dedupeKey && ($existing = $this->findByIdempotencyKey((int) $account->user_id, $dedupeKey))) {
                return ['message' => $existing, 'duplicate' => true];
            }
            throw $e;
        }

        $this->emit($message, 'whatsapp.message.received');
        event(new WhatsAppMessageReceived($message));

        return ['message' => $message, 'duplicate' => false];
    }

    /**
     * What we know from history: true (has interacted on WhatsApp), false
     * (provider reported the number is not on WhatsApp), null (unknown).
     */
    public function knownWhatsAppStatus(int $accountId, string $phone): ?bool
    {
        $positive = WhatsAppMessage::query()
            ->where('user_id', $accountId)
            ->where(function ($q) use ($phone) {
                $q->where(fn ($q) => $q->where('direction', 'incoming')->where('sender', $phone))
                    ->orWhere(fn ($q) => $q->where('direction', 'outgoing')->where('recipient', $phone)
                        ->whereIn('status', [WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ]));
            })
            ->latest('id')
            ->value('created_at');

        $negative = WhatsAppMessage::query()
            ->where('user_id', $accountId)
            ->where('direction', 'outgoing')
            ->where('recipient', $phone)
            ->where('error_code', 'NOT_ON_WHATSAPP')
            ->latest('id')
            ->value('created_at');

        if ($positive && (! $negative || Carbon::parse($positive)->gte(Carbon::parse($negative)))) {
            return true;
        }

        return $negative ? false : null;
    }

    public function emit(WhatsAppMessage $message, string $event): void
    {
        DispatchWebhookJob::dispatch((int) $message->user_id, $event, [
            'event' => $event,
            'message' => $message->fresh(['template'])->toApiArray(),
        ]);
    }

    private function findByIdempotencyKey(int $accountId, string $key): ?WhatsAppMessage
    {
        return WhatsAppMessage::query()
            ->where('user_id', $accountId)
            ->where('idempotency_key', $key)
            ->first();
    }
}
