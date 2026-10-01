<?php

namespace App\Jobs;

use App\Models\AutomationRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Delivers an automation "forward_webhook" action (e.g. to a CRM),
 * signed the same way as regular gateway webhooks.
 */
class CallRuleWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $ruleId, public array $payload)
    {
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(): void
    {
        $rule = AutomationRule::find($this->ruleId);
        $url = $rule?->action_config['url'] ?? null;

        if (! $rule || ! $rule->is_active || ! $url) {
            return;
        }

        $body = json_encode($this->payload);

        $response = Http::timeout(10)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-SMS-Gateway-Event' => 'automation.rule_triggered',
                'X-SMS-Gateway-Signature' => hash_hmac('sha256', $body, (string) ($rule->action_config['secret'] ?? '')),
            ])
            ->withBody($body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Automation webhook delivery failed with HTTP '.$response->status());
        }
    }
}
