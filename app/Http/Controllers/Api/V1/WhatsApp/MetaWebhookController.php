<?php

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    /**
     * Subscription handshake: echo hub.challenge only when the verify token matches.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('whatsapp.meta.verify_token');

        if ($expected !== ''
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $secret = (string) config('whatsapp.meta.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        $raw = $request->getContent();

        if ($secret === '') {
            Log::error('Meta webhook rejected: META_APP_SECRET is not configured');

            return response()->json(['message' => 'Webhook not configured'], 503);
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            Log::warning('Meta webhook rejected: invalid signature', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['message' => 'Ignored'], 200);
        }

        ProcessMetaWebhookJob::dispatch($payload);

        return response()->json(['message' => 'Accepted'], 200);
    }
}
