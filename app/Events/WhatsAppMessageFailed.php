<?php

namespace App\Events;

use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once a message has permanently failed (retries exhausted or non-retryable error).
 */
class WhatsAppMessageFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public WhatsAppMessage $message)
    {
    }
}
