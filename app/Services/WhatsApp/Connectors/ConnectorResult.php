<?php

namespace App\Services\WhatsApp\Connectors;

final class ConnectorResult
{
    public const ACCEPTED = 'accepted';

    public const AWAITING_DEVICE = 'awaiting_device';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $failureReason = null,
        public readonly bool $retryable = false,
    ) {
    }

    /** Provider accepted the message for delivery (status becomes "sent"). */
    public static function accepted(?string $providerMessageId): self
    {
        return new self(self::ACCEPTED, $providerMessageId);
    }

    /** Message stays queued until the linked phone pulls it on its next sync. */
    public static function awaitingDevice(): self
    {
        return new self(self::AWAITING_DEVICE);
    }

    public static function failed(string $errorCode, string $reason, bool $retryable): self
    {
        return new self(self::FAILED, null, $errorCode, $reason, $retryable);
    }
}
