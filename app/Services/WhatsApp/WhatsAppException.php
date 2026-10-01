<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class WhatsAppException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->context), $this->status);
    }
}
