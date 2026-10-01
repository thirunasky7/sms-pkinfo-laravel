<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retries are managed by WhatsAppMessageService (retry_count + scheduled_at)
 * so they are visible in message history; the job itself runs once.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
    }

    public function handle(WhatsAppMessageService $service): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (! $message || $message->direction !== 'outgoing') {
            return;
        }

        if (! $service->claim($message)) {
            return;
        }

        $service->attempt($message);
    }
}
