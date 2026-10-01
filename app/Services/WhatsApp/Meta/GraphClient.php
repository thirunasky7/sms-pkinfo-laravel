<?php

namespace App\Services\WhatsApp\Meta;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the WhatsApp Business Platform (Graph API).
 * Access tokens are passed per call and are never logged.
 */
class GraphClient
{
    public function sendMessage(string $phoneNumberId, string $token, array $payload): array
    {
        return $this->call('post', "{$phoneNumberId}/messages", $token, $payload);
    }

    public function getPhoneNumber(string $phoneNumberId, string $token): array
    {
        return $this->call('get', $phoneNumberId, $token, [
            'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,name_status',
        ]);
    }

    public function registerPhoneNumber(string $phoneNumberId, string $token, string $pin): array
    {
        return $this->call('post', "{$phoneNumberId}/register", $token, [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]);
    }

    public function subscribeApp(string $wabaId, string $token): array
    {
        return $this->call('post', "{$wabaId}/subscribed_apps", $token);
    }

    public function listTemplates(string $wabaId, string $token, ?string $after = null): array
    {
        return $this->call('get', "{$wabaId}/message_templates", $token, array_filter([
            'fields' => 'id,name,language,status,category,components,rejected_reason',
            'limit' => 100,
            'after' => $after,
        ]));
    }

    public function createTemplate(string $wabaId, string $token, array $payload): array
    {
        return $this->call('post', "{$wabaId}/message_templates", $token, $payload);
    }

    public function editTemplate(string $templateId, string $token, array $payload): array
    {
        return $this->call('post', $templateId, $token, $payload);
    }

    public function deleteTemplate(string $wabaId, string $token, string $name, ?string $templateId = null): array
    {
        return $this->call('delete', "{$wabaId}/message_templates", $token, array_filter([
            'name' => $name,
            'hsm_id' => $templateId,
        ]));
    }

    /**
     * Exchange the Embedded Signup authorization code for a business token.
     */
    public function exchangeCode(string $code): array
    {
        return $this->appCall('get', 'oauth/access_token', [
            'client_id' => config('whatsapp.meta.app_id'),
            'client_secret' => config('whatsapp.meta.app_secret'),
            'code' => $code,
        ]);
    }

    public function exchangeLongLivedToken(string $token): array
    {
        return $this->appCall('get', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('whatsapp.meta.app_id'),
            'client_secret' => config('whatsapp.meta.app_secret'),
            'fb_exchange_token' => $token,
        ]);
    }

    public function debugToken(string $token): array
    {
        return $this->appCall('get', 'debug_token', [
            'input_token' => $token,
            'access_token' => config('whatsapp.meta.app_id').'|'.config('whatsapp.meta.app_secret'),
        ]);
    }

    private function call(string $method, string $path, string $token, array $data = []): array
    {
        $request = $this->http()->withToken($token);

        $response = $method === 'delete'
            ? $request->delete($this->url($path).($data ? '?'.http_build_query($data) : ''))
            : $request->{$method}($this->url($path), $data);

        return $this->handle($response, $path);
    }

    private function appCall(string $method, string $path, array $query): array
    {
        return $this->handle($this->http()->{$method}($this->url($path), $query), $path);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('whatsapp.meta.timeout', 20))
            ->connectTimeout(10);
    }

    private function url(string $path): string
    {
        return rtrim(config('whatsapp.meta.graph_url'), '/').'/'.config('whatsapp.meta.graph_version').'/'.ltrim($path, '/');
    }

    private function handle(Response $response, string $path): array
    {
        $json = $response->json() ?? [];

        if ($response->successful() && ! isset($json['error'])) {
            return $json;
        }

        $error = $json['error'] ?? [];
        $exception = new MetaApiException(
            (string) ($error['error_user_msg'] ?? $error['message'] ?? 'Meta API request failed'),
            (int) ($error['code'] ?? 0),
            isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            $response->status(),
            $error['fbtrace_id'] ?? null,
        );

        // Path only (no query string): it never contains tokens.
        Log::warning('Meta Graph API error', [
            'path' => preg_replace('/\?.*$/', '', $path),
            'http_status' => $response->status(),
            'code' => $exception->metaCode,
            'subcode' => $exception->metaSubcode,
            'fbtrace_id' => $exception->fbtraceId,
        ]);

        throw $exception;
    }
}
