<?php

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\Messaging\MessageRouter;
use App\Services\WhatsApp\AccountResolver;
use App\Services\WhatsApp\MediaStorage;
use App\Services\WhatsApp\PhoneNumber;
use App\Services\WhatsApp\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function send(Request $request, MessageRouter $router, MediaStorage $media): JsonResponse
    {
        $data = $request->validate([
            'to' => 'required|string|max:32',
            'type' => 'nullable|in:text,image,video,audio,document',
            'body' => 'required_without_all:media,media_url|nullable|string|max:4096',
            'media' => 'nullable|file|max:'.config('whatsapp.media.max_kb').'|mimes:'.config('whatsapp.media.mimes'),
            'media_url' => 'nullable|url|starts_with:https://|max:2048',
            'filename' => 'nullable|string|max:255',
            'account_id' => 'nullable|integer',
            'connector' => 'nullable|in:device,cloud_api',
            'external_id' => 'nullable|string|max:255',
            'idempotency_key' => 'nullable|string|max:128',
            'scheduled_at' => 'nullable|date',
        ]);

        $type = $data['type'] ?? (($request->hasFile('media') || ! empty($data['media_url'])) ? 'document' : 'text');

        if ($type === 'text' && ($request->hasFile('media') || ! empty($data['media_url']))) {
            return response()->json(['message' => 'Set "type" when sending media', 'error_code' => 'VALIDATION_ERROR'], 422);
        }
        if ($type !== 'text' && ! $request->hasFile('media') && empty($data['media_url'])) {
            return response()->json(['message' => 'media or media_url is required for media messages', 'error_code' => 'VALIDATION_ERROR'], 422);
        }
        if ($type === 'audio' && ! empty($data['body'])) {
            return response()->json(['message' => 'Audio messages cannot have a caption', 'error_code' => 'VALIDATION_ERROR'], 422);
        }

        $accountId = $request->user()->ownsAccountId();
        $mediaAttrs = [];

        if ($request->hasFile('media')) {
            $mediaAttrs = $media->store($request->file('media'), $accountId);
        } elseif (! empty($data['media_url'])) {
            $mediaAttrs = [
                'media_url' => $data['media_url'],
                'media_mime' => null,
                'media_filename' => $data['filename'] ?? basename(parse_url($data['media_url'], PHP_URL_PATH) ?: 'file'),
            ];
        }

        $result = $router->send(MessageRouter::CHANNEL_WHATSAPP, $accountId, array_merge([
            'to' => $data['to'],
            'message_type' => $type,
            'body' => $data['body'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'connector' => $data['connector'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'idempotency_key' => $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null),
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ], $mediaAttrs), [
            'api_key_id' => $request->attributes->get('api_key')?->id,
            'subscription' => $request->attributes->get('subscription'),
        ]);

        return $this->queuedResponse($result['message'], $result['duplicate']);
    }

    public function checkNumber(Request $request, WhatsAppMessageService $service, AccountResolver $accounts): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32',
        ]);

        $phone = PhoneNumber::normalize($data['phone']);

        if (! $phone) {
            return response()->json([
                'message' => 'The phone number is not a valid international number',
                'error_code' => 'INVALID_NUMBER',
                'phone' => $data['phone'],
                'valid' => false,
            ], 422);
        }

        $accountId = $request->user()->ownsAccountId();
        $known = $service->knownWhatsAppStatus($accountId, $phone);

        return response()->json([
            'phone' => $phone,
            'valid' => true,
            'on_whatsapp' => $known,
            'status' => match ($known) {
                true => 'on_whatsapp',
                false => 'not_on_whatsapp',
                default => 'unknown',
            },
            'source' => $known === null ? null : 'message_history',
            'note' => $known === null
                ? 'WhatsApp does not provide a number lookup API. Status is confirmed after the first delivery attempt.'
                : null,
            'has_connected_account' => (bool) $accounts->resolve($accountId),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'direction' => 'nullable|in:incoming,outgoing',
            'status' => 'nullable|in:queued,sending,sent,delivered,read,failed,received',
            'connector' => 'nullable|in:device,cloud_api',
            'type' => 'nullable|in:text,image,video,audio,document,template',
            'phone' => 'nullable|string|max:32',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $phone = PhoneNumber::normalize($request->phone);

        $messages = WhatsAppMessage::query()
            ->with('template')
            ->where('user_id', $request->user()->ownsAccountId())
            ->when($request->direction, fn ($q, $v) => $q->where('direction', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->connector, fn ($q, $v) => $q->where('connector_type', $v))
            ->when($request->type, fn ($q, $v) => $q->where('message_type', $v))
            ->when($phone, fn ($q, $v) => $q->where(fn ($q) => $q->where('recipient', $v)->orWhere('sender', $v)))
            ->when($request->from, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->latest('id')
            ->paginate((int) ($request->per_page ?? 50));

        return response()->json([
            'data' => collect($messages->items())->map->toApiArray(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
                'last_page' => $messages->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $messageId): JsonResponse
    {
        $message = WhatsAppMessage::query()
            ->with('template')
            ->where('user_id', $request->user()->ownsAccountId())
            ->where('message_id', $messageId)
            ->first();

        if (! $message) {
            return response()->json(['message' => 'Message not found', 'error_code' => 'NOT_FOUND'], 404);
        }

        return response()->json(['message' => $message->toApiArray()]);
    }

    protected function queuedResponse(WhatsAppMessage $message, bool $duplicate): JsonResponse
    {
        $account = $message->account;

        return response()->json(array_filter([
            'message' => $message->toApiArray(),
            'duplicate' => $duplicate,
            'warning' => $account && $account->isDevice() && ! $account->isConnected()
                ? 'The linked device is disconnected; the message will be sent when it reconnects.'
                : null,
        ], fn ($v) => $v !== null), $duplicate ? 200 : 202);
    }
}
