<?php

namespace App\Services\WhatsApp\Meta;

/**
 * Maps Meta (Graph / Cloud API) error codes to gateway error codes.
 * See https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
 */
class MetaErrorMapper
{
    /**
     * code => [gateway_code, retryable, human message]
     */
    private const MAP = [
        0 => ['TOKEN_INVALID', false, 'Meta could not authenticate the access token'],
        190 => ['TOKEN_EXPIRED', false, 'The Meta access token expired or was revoked; reconnect the account'],
        10 => ['PERMISSION_DENIED', false, 'The app lacks permission for this WhatsApp Business Account'],
        3 => ['PERMISSION_DENIED', false, 'The app does not have the required capability'],
        1 => ['PROVIDER_ERROR', true, 'Meta API returned an unknown error'],
        2 => ['PROVIDER_UNAVAILABLE', true, 'Meta API is temporarily unavailable'],
        4 => ['RATE_LIMITED', true, 'Meta app-level rate limit reached'],
        80007 => ['RATE_LIMITED', true, 'WhatsApp Business Account rate limit reached'],
        130429 => ['RATE_LIMITED', true, 'Cloud API throughput limit reached'],
        131056 => ['RATE_LIMITED', true, 'Too many messages to this recipient in a short time'],
        131048 => ['SPAM_RATE_LIMITED', false, 'Messaging limited because of quality/spam signals'],
        368 => ['POLICY_BLOCKED', false, 'The account is temporarily blocked for policy violations'],
        131031 => ['ACCOUNT_LOCKED', false, 'The WhatsApp Business Account is locked'],
        131042 => ['BILLING_ISSUE', false, 'There is a payment issue on the WhatsApp Business Account'],
        131045 => ['PHONE_NOT_REGISTERED', false, 'The business phone number is not registered on Cloud API'],
        133010 => ['PHONE_NOT_REGISTERED', false, 'The business phone number is not registered on Cloud API'],
        131026 => ['NOT_ON_WHATSAPP', false, 'Message undeliverable: the recipient may not be on WhatsApp or cannot receive this message'],
        131021 => ['INVALID_RECIPIENT', false, 'Recipient cannot be the sender'],
        131047 => ['OUTSIDE_24H_WINDOW', false, 'More than 24 hours since the customer last replied; send an approved template'],
        470 => ['OUTSIDE_24H_WINDOW', false, 'More than 24 hours since the customer last replied; send an approved template'],
        131049 => ['META_LIMITED', false, 'Meta chose not to deliver this marketing message to protect user experience'],
        130472 => ['META_LIMITED', false, 'Recipient is part of a Meta experiment and did not receive the message'],
        131051 => ['UNSUPPORTED_MESSAGE_TYPE', false, 'Unsupported message type'],
        131052 => ['MEDIA_DOWNLOAD_FAILED', true, 'Meta could not download the media'],
        131053 => ['MEDIA_UPLOAD_FAILED', false, 'Media could not be processed; check type, size and URL'],
        100 => ['INVALID_REQUEST', false, 'Invalid parameter in the request'],
        131008 => ['INVALID_REQUEST', false, 'A required parameter is missing'],
        131009 => ['INVALID_REQUEST', false, 'A parameter value is invalid'],
        132000 => ['TEMPLATE_PARAM_MISMATCH', false, 'Template variable count does not match'],
        132001 => ['TEMPLATE_NOT_FOUND', false, 'Template does not exist in this language or is not approved'],
        132005 => ['TEMPLATE_TEXT_TOO_LONG', false, 'Template text is too long after variables were filled'],
        132007 => ['TEMPLATE_POLICY_VIOLATION', false, 'Template content violates WhatsApp policy'],
        132012 => ['TEMPLATE_PARAM_MISMATCH', false, 'Template variable format does not match'],
        132015 => ['TEMPLATE_PAUSED', false, 'Template is paused because of low quality'],
        132016 => ['TEMPLATE_DISABLED', false, 'Template is disabled'],
        131000 => ['PROVIDER_ERROR', true, 'Meta failed to send the message'],
        131016 => ['PROVIDER_UNAVAILABLE', true, 'Meta service is temporarily unavailable'],
        135000 => ['PROVIDER_ERROR', true, 'Meta generic user error'],
    ];

    /**
     * @return array{code: string, retryable: bool, message: string, meta_code: int}
     */
    public function map(int $metaCode, ?string $metaMessage = null, int $httpStatus = 0): array
    {
        if (isset(self::MAP[$metaCode])) {
            [$code, $retryable, $message] = self::MAP[$metaCode];
        } elseif ($metaCode >= 200 && $metaCode <= 299) {
            [$code, $retryable, $message] = ['PERMISSION_DENIED', false, 'Permission error from Meta'];
        } elseif ($httpStatus >= 500 || $httpStatus === 0) {
            [$code, $retryable, $message] = ['PROVIDER_ERROR', true, 'Meta API server error'];
        } else {
            [$code, $retryable, $message] = ['UNKNOWN_ERROR', false, 'Meta rejected the request'];
        }

        if ($metaMessage && ! str_contains($message, $metaMessage)) {
            $message .= ' (Meta '.$metaCode.': '.mb_substr($metaMessage, 0, 300).')';
        }

        return ['code' => $code, 'retryable' => $retryable, 'message' => $message, 'meta_code' => $metaCode];
    }

    public function isCredentialError(string $gatewayCode): bool
    {
        return in_array($gatewayCode, ['TOKEN_INVALID', 'TOKEN_EXPIRED', 'PERMISSION_DENIED', 'ACCOUNT_LOCKED', 'PHONE_NOT_REGISTERED'], true);
    }
}
