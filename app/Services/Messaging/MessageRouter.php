<?php

namespace App\Services\Messaging;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Sms\SmsGateway;
use App\Services\WhatsApp\AccountResolver;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppMessageService;

/**
 * Single entry point for sending on any channel. The SMS branch delegates to
 * the unchanged SMS gateway; WhatsApp goes through the WhatsApp message service.
 */
class MessageRouter
{
    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public function __construct(
        private readonly SmsGateway $sms,
        private readonly WhatsAppMessageService $whatsapp,
        private readonly AccountResolver $accounts,
    ) {
    }

    /**
     * @return array{channel: string, message: mixed, duplicate?: bool, error?: ?string}
     */
    public function send(string $channel, int $accountId, array $payload, array $context = []): array
    {
        return match ($channel) {
            self::CHANNEL_SMS => $this->sendSms($accountId, $payload),
            self::CHANNEL_WHATSAPP => $this->sendWhatsApp($accountId, $payload, $context),
            default => throw new \InvalidArgumentException("Unknown channel [{$channel}]"),
        };
    }

    private function sendSms(int $accountId, array $payload): array
    {
        $subscription = $this->activeSubscription($accountId);

        if (! $subscription || ! $subscription->allowsSend()) {
            return ['channel' => self::CHANNEL_SMS, 'message' => null, 'error' => 'SMS limit reached or subscription expired'];
        }

        $result = $this->sms->queue($accountId, [
            'to' => $payload['to'],
            'body' => mb_substr((string) $payload['body'], 0, 1600),
            'device_id' => $payload['device_id'] ?? null,
            'external_id' => $payload['external_id'] ?? null,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
        ], $subscription);

        return ['channel' => self::CHANNEL_SMS, 'message' => $result['message'], 'error' => $result['error']];
    }

    private function sendWhatsApp(int $accountId, array $payload, array $context): array
    {
        if (! config('whatsapp.enabled')) {
            throw new WhatsAppException('WHATSAPP_DISABLED', 'WhatsApp sending is temporarily disabled', 503);
        }

        $account = $this->accounts->resolve($accountId, $payload['account_id'] ?? null, $payload['connector'] ?? null);

        if (! $account) {
            throw new WhatsAppException('NO_WHATSAPP_ACCOUNT', 'No connected WhatsApp account is available', 409);
        }

        $context['subscription'] ??= $this->activeSubscription($accountId);
        if (! $context['subscription'] || ! $context['subscription']->allowsWhatsApp()) {
            throw new WhatsAppException('QUOTA_EXCEEDED', 'WhatsApp limit reached or subscription expired', 402);
        }

        $result = $this->whatsapp->queueOutgoing($account, $payload, $context);

        return ['channel' => self::CHANNEL_WHATSAPP] + $result;
    }

    private function activeSubscription(int $accountId): ?Subscription
    {
        return User::find($accountId)?->activeSubscription()->with('plan')->first();
    }
}
