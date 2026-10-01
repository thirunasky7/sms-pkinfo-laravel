<?php

namespace App\Listeners;

use App\Events\SmsMessageReceived;
use App\Events\WhatsAppMessageFailed;
use App\Events\WhatsAppMessageReceived;
use App\Services\Automation\RuleEngine;
use Illuminate\Events\Dispatcher;

class RunAutomationRules
{
    public function __construct(private readonly RuleEngine $engine)
    {
    }

    public function handleWhatsAppReceived(WhatsAppMessageReceived $event): void
    {
        $this->engine->onWhatsAppIncoming($event->message);
    }

    public function handleWhatsAppFailed(WhatsAppMessageFailed $event): void
    {
        $this->engine->onWhatsAppFailed($event->message);
    }

    public function handleSmsReceived(SmsMessageReceived $event): void
    {
        $this->engine->onSmsIncoming($event->message);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            WhatsAppMessageReceived::class => 'handleWhatsAppReceived',
            WhatsAppMessageFailed::class => 'handleWhatsAppFailed',
            SmsMessageReceived::class => 'handleSmsReceived',
        ];
    }
}
