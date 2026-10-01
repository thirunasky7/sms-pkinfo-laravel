<?php

namespace App\Services\WhatsApp\Connectors;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\Meta\GraphClient;
use App\Services\WhatsApp\Meta\MetaApiException;
use App\Services\WhatsApp\Meta\MetaErrorMapper;
use App\Services\WhatsApp\Meta\TemplatePayloadBuilder;

class CloudApiConnector implements Connector
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly MetaErrorMapper $errors,
        private readonly TemplatePayloadBuilder $templates,
    ) {
    }

    public function send(WhatsAppMessage $message): ConnectorResult
    {
        $account = $message->account;

        if (! $account->phone_number_id || ! $account->hasToken()) {
            return ConnectorResult::failed('ACCOUNT_NOT_CONFIGURED', 'Cloud API credentials are missing; reconnect the account', false);
        }

        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $this->flagAccount($account, 'Access token expired');

            return ConnectorResult::failed('TOKEN_EXPIRED', 'The Meta access token expired; reconnect the account', false);
        }

        try {
            $response = $this->graph->sendMessage($account->phone_number_id, $account->access_token, $this->payload($message));
        } catch (MetaApiException $e) {
            $mapped = $this->errors->map($e->metaCode, $e->getMessage(), $e->httpStatus);
            if ($this->errors->isCredentialError($mapped['code'])) {
                $this->flagAccount($account, $mapped['message']);
            }

            return ConnectorResult::failed($mapped['code'], $mapped['message'], $mapped['retryable']);
        }

        $wamid = $response['messages'][0]['id'] ?? null;

        return ConnectorResult::accepted($wamid);
    }

    public function payload(WhatsAppMessage $message): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->recipient,
        ];

        if ($message->message_type === 'template') {
            $template = $message->template;

            return $base + [
                'type' => 'template',
                'template' => array_filter([
                    'name' => $template->name,
                    'language' => ['code' => $template->language],
                    'components' => $this->templates->messageComponents($template, $message->template_params ?? []) ?: null,
                ]),
            ];
        }

        if ($message->message_type === 'text') {
            return $base + [
                'type' => 'text',
                'text' => ['preview_url' => true, 'body' => (string) $message->body],
            ];
        }

        $media = ['link' => $message->media_url];
        if ($message->body && $message->message_type !== 'audio') {
            $media['caption'] = $message->body;
        }
        if ($message->message_type === 'document' && $message->media_filename) {
            $media['filename'] = $message->media_filename;
        }

        return $base + [
            'type' => $message->message_type,
            $message->message_type => $media,
        ];
    }

    private function flagAccount(WhatsAppAccount $account, string $reason): void
    {
        $account->update([
            'status' => WhatsAppAccount::STATUS_ERROR,
            'last_error' => $reason,
        ]);
    }
}
