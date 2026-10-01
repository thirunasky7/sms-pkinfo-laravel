<?php

namespace App\Services\WhatsApp\Meta;

use RuntimeException;

class MetaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $metaCode = 0,
        public readonly ?int $metaSubcode = null,
        public readonly int $httpStatus = 0,
        public readonly ?string $fbtraceId = null,
    ) {
        parent::__construct($message, $metaCode);
    }
}
