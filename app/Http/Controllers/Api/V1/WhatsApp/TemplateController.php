<?php

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\MessageRouter;
use App\Services\WhatsApp\AccountResolver;
use App\Services\WhatsApp\TemplateService;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:'.implode(',', WhatsAppTemplate::STATUSES),
            'account_id' => 'nullable|integer',
        ]);

        $templates = WhatsAppTemplate::query()
            ->where('user_id', $request->user()->ownsAccountId())
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->account_id, fn ($q, $v) => $q->where('whatsapp_account_id', $v))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $templates->map->toApiArray()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['template' => $this->find($request, $id)->toApiArray()]);
    }

    public function store(Request $request, TemplateService $service, AccountResolver $accounts): JsonResponse
    {
        $data = $request->validate(TemplateService::rules());
        $accountId = $request->user()->ownsAccountId();

        $account = $accounts->resolve($accountId, $data['account_id'] ?? null);
        if (! $account) {
            throw new WhatsAppException('NO_WHATSAPP_ACCOUNT', 'No connected WhatsApp account is available', 409);
        }

        $template = $service->create($account, $data, $this->actorId($request));

        return response()->json(['template' => $template->toApiArray()], 201);
    }

    public function update(Request $request, int $id, TemplateService $service): JsonResponse
    {
        $template = $this->find($request, $id);
        $data = $request->validate(TemplateService::rules(true));

        $template = $service->update($template, $data, $this->actorId($request));

        return response()->json(['template' => $template->toApiArray()]);
    }

    public function destroy(Request $request, int $id, TemplateService $service): JsonResponse
    {
        $service->delete($this->find($request, $id), $this->actorId($request));

        return response()->json(['message' => 'Template deleted']);
    }

    public function sync(Request $request, TemplateService $service): JsonResponse
    {
        $result = ['synced' => 0, 'disabled' => 0];

        WhatsAppAccount::query()
            ->forAccount($request->user()->ownsAccountId())
            ->where('connector_type', WhatsAppAccount::CONNECTOR_CLOUD_API)
            ->usable()
            ->each(function (WhatsAppAccount $account) use ($service, &$result) {
                $r = $service->sync($account);
                $result['synced'] += $r['synced'];
                $result['disabled'] += $r['disabled'];
            });

        return response()->json($result);
    }

    public function send(Request $request, MessageRouter $router, TemplateService $service): JsonResponse
    {
        $data = $request->validate([
            'to' => 'required|string|max:32',
            'template_id' => 'required_without:template_name|nullable|integer',
            'template_name' => 'required_without:template_id|nullable|string|max:512',
            'language' => 'required_with:template_name|nullable|string|max:16',
            'account_id' => 'nullable|integer',
            'params' => 'nullable|array',
            'params.header' => 'nullable|array|max:1',
            'params.header.*' => 'string|max:60',
            'params.header_media_url' => 'nullable|url|starts_with:https://|max:2048',
            'params.body' => 'nullable|array|max:20',
            'params.body.*' => 'string|max:1024',
            'params.buttons' => 'nullable|array|max:10',
            'params.buttons.*.index' => 'required_with:params.buttons|integer|min:0|max:9',
            'params.buttons.*.sub_type' => 'nullable|in:url,quick_reply,copy_code',
            'params.buttons.*.value' => 'required_with:params.buttons|string|max:200',
            'external_id' => 'nullable|string|max:255',
            'idempotency_key' => 'nullable|string|max:128',
            'scheduled_at' => 'nullable|date',
        ]);

        $accountId = $request->user()->ownsAccountId();

        $template = WhatsAppTemplate::query()
            ->where('user_id', $accountId)
            ->when($data['template_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when(empty($data['template_id']), fn ($q) => $q
                ->where('name', $data['template_name'])
                ->where('language', $data['language']))
            ->when($data['account_id'] ?? null, fn ($q, $id) => $q->where('whatsapp_account_id', $id))
            ->first();

        if (! $template) {
            throw new WhatsAppException('TEMPLATE_NOT_FOUND', 'Template not found', 404);
        }

        $params = $data['params'] ?? [];
        $service->assertParams($template, $params);

        $result = $router->send(MessageRouter::CHANNEL_WHATSAPP, $accountId, [
            'to' => $data['to'],
            'account_id' => $template->whatsapp_account_id,
            'template' => $template,
            'template_params' => $params,
            'media_url' => in_array($template->header_type, ['image', 'video', 'document'], true)
                ? ($params['header_media_url'] ?? $template->header_media_url) : null,
            'external_id' => $data['external_id'] ?? null,
            'idempotency_key' => $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null),
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ], [
            'api_key_id' => $request->attributes->get('api_key')?->id,
            'subscription' => $request->attributes->get('subscription'),
        ]);

        return response()->json(array_filter([
            'message' => $result['message']->toApiArray(),
            'duplicate' => $result['duplicate'],
        ], fn ($v) => $v !== null), $result['duplicate'] ? 200 : 202);
    }

    private function find(Request $request, int $id): WhatsAppTemplate
    {
        return WhatsAppTemplate::query()
            ->where('user_id', $request->user()->ownsAccountId())
            ->findOrFail($id);
    }

    private function actorId(Request $request): int
    {
        return (int) $request->user()->id;
    }
}
