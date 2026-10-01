<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\Meta\GraphClient;
use App\Services\WhatsApp\Meta\MetaApiException;
use App\Services\WhatsApp\Meta\MetaErrorMapper;
use App\Services\WhatsApp\Meta\MetaStatusMapper;
use App\Services\WhatsApp\Meta\TemplatePayloadBuilder;
use App\Support\Audit;
use Illuminate\Validation\Rule;

/**
 * Cloud API templates are submitted to Meta and their status (pending /
 * approved / rejected …) is owned by Meta. Device-connector templates are
 * local message presets and are usable immediately.
 */
class TemplateService
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly TemplatePayloadBuilder $builder,
        private readonly MetaStatusMapper $statuses,
        private readonly MetaErrorMapper $errors,
    ) {
    }

    public static function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'account_id' => [$updating ? 'prohibited' : 'nullable', 'integer'],
            'name' => [$updating ? 'prohibited' : 'required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => [$updating ? 'prohibited' : 'required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'category' => [$required, Rule::in(WhatsAppTemplate::CATEGORIES)],
            'header_type' => ['nullable', Rule::in(WhatsAppTemplate::HEADER_TYPES)],
            'header_text' => ['nullable', 'required_if:header_type,text', 'string', 'max:60'],
            'header_media_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'body' => [$required, 'string', 'max:1024'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required_with:buttons', Rule::in(['quick_reply', 'url', 'phone_number', 'copy_code'])],
            'buttons.*.text' => ['nullable', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'required_if:buttons.*.type,url', 'string', 'max:2000'],
            'buttons.*.phone_number' => ['nullable', 'required_if:buttons.*.type,phone_number', 'string', 'max:20'],
            'buttons.*.example' => ['nullable', 'string', 'max:200'],
            'examples' => ['nullable', 'array'],
            'examples.header' => ['nullable', 'array'],
            'examples.body' => ['nullable', 'array'],
            'examples.header_handle' => ['nullable', 'string'],
        ];
    }

    public function create(WhatsAppAccount $account, array $data, int $actorId): WhatsAppTemplate
    {
        $exists = WhatsAppTemplate::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('name', $data['name'])
            ->where('language', $data['language'])
            ->exists();
        if ($exists) {
            throw new WhatsAppException('TEMPLATE_EXISTS', 'A template with this name and language already exists', 409);
        }

        $template = new WhatsAppTemplate($this->attributes($data) + [
            'user_id' => $account->user_id,
            'whatsapp_account_id' => $account->id,
            'name' => $data['name'],
            'language' => $data['language'],
        ]);
        $this->assertExamples($template);

        if ($account->isCloudApi()) {
            $result = $this->guard(fn () => $this->graph->createTemplate($account->waba_id, $account->access_token, $this->builder->definition($template)));
            $template->provider_template_id = $result['id'] ?? null;
            $template->status = $this->statuses->mapTemplateStatus($result['status'] ?? 'PENDING');
            $template->category = strtolower($result['category'] ?? $template->category);
            $template->last_synced_at = now();
        } else {
            $template->status = 'approved';
        }

        $template->save();
        Audit::log($actorId, 'whatsapp.template.created', $template, ['name' => $template->name, 'language' => $template->language]);

        return $template;
    }

    public function update(WhatsAppTemplate $template, array $data, int $actorId): WhatsAppTemplate
    {
        $template->fill($this->attributes($data, $template));
        $this->assertExamples($template);
        $account = $template->account;

        if ($account->isCloudApi()) {
            if (! $template->provider_template_id) {
                throw new WhatsAppException('TEMPLATE_NOT_SYNCED', 'Sync templates before editing this template', 409);
            }
            $definition = $this->builder->definition($template);
            $this->guard(fn () => $this->graph->editTemplate($template->provider_template_id, $account->access_token, [
                'category' => $definition['category'],
                'components' => $definition['components'],
            ]));
            // Edits go back through Meta review.
            $template->status = 'pending';
            $template->rejection_reason = null;
            $template->last_synced_at = now();
        }

        $template->save();
        Audit::log($actorId, 'whatsapp.template.updated', $template, ['name' => $template->name]);

        return $template;
    }

    public function delete(WhatsAppTemplate $template, int $actorId): void
    {
        $account = $template->account;

        if ($account->isCloudApi() && $account->hasToken() && $template->provider_template_id) {
            $this->guard(fn () => $this->graph->deleteTemplate($account->waba_id, $account->access_token, $template->name, $template->provider_template_id));
        }

        Audit::log($actorId, 'whatsapp.template.deleted', $template, ['name' => $template->name, 'language' => $template->language]);
        $template->delete();
    }

    /**
     * Pull every template from Meta and upsert local copies.
     *
     * @return array{synced: int, disabled: int}
     */
    public function sync(WhatsAppAccount $account): array
    {
        if (! $account->isCloudApi() || ! $account->hasToken() || ! $account->waba_id) {
            return ['synced' => 0, 'disabled' => 0];
        }

        $seen = [];
        $after = null;
        $pages = 0;

        do {
            $page = $this->guard(fn () => $this->graph->listTemplates($account->waba_id, $account->access_token, $after));

            foreach ($page['data'] ?? [] as $remote) {
                $template = WhatsAppTemplate::query()->firstOrNew([
                    'whatsapp_account_id' => $account->id,
                    'name' => $remote['name'],
                    'language' => $remote['language'],
                ]);

                $category = strtolower($remote['category'] ?? $template->category ?? 'utility');

                $template->fill($this->builder->fromMeta($remote) + [
                    'user_id' => $account->user_id,
                    'category' => in_array($category, WhatsAppTemplate::CATEGORIES, true) ? $category : 'utility',
                    'status' => $this->statuses->mapTemplateStatus($remote['status'] ?? 'PENDING'),
                    'provider_template_id' => $remote['id'] ?? $template->provider_template_id,
                    'rejection_reason' => ($remote['rejected_reason'] ?? 'NONE') !== 'NONE' ? $remote['rejected_reason'] : null,
                    'last_synced_at' => now(),
                ])->save();

                $seen[] = $template->id;
            }

            $after = $page['paging']['cursors']['after'] ?? null;
            $hasNext = ! empty($page['paging']['next']);
        } while ($hasNext && $after && ++$pages < 50);

        $disabled = WhatsAppTemplate::query()
            ->where('whatsapp_account_id', $account->id)
            ->whereNotNull('provider_template_id')
            ->whereNotIn('id', $seen ?: [0])
            ->update(['status' => 'disabled', 'last_synced_at' => now()]);

        return ['synced' => count($seen), 'disabled' => $disabled];
    }

    /**
     * Validate send-time params against the template's placeholders.
     */
    public function assertParams(WhatsAppTemplate $template, array $params): void
    {
        $bodyNeeded = $template->bodyVariableCount();
        $bodyGiven = count($params['body'] ?? []);
        $headerNeeded = $template->headerVariableCount();
        $headerGiven = count($params['header'] ?? []);

        if ($bodyNeeded !== $bodyGiven || $headerNeeded !== $headerGiven) {
            throw new WhatsAppException('TEMPLATE_PARAM_MISMATCH', 'Template variables do not match the template', 422, [
                'expected' => ['header' => $headerNeeded, 'body' => $bodyNeeded],
                'received' => ['header' => $headerGiven, 'body' => $bodyGiven],
            ]);
        }

        if (in_array($template->header_type, ['image', 'video', 'document'], true)
            && empty($params['header_media_url']) && empty($template->header_media_url)) {
            throw new WhatsAppException('TEMPLATE_MEDIA_REQUIRED', 'This template needs params.header_media_url', 422);
        }
    }

    private function attributes(array $data, ?WhatsAppTemplate $existing = null): array
    {
        $headerType = $data['header_type'] ?? $existing?->header_type ?? 'none';

        return array_filter([
            'category' => $data['category'] ?? $existing?->category,
            'header_type' => $headerType,
            'header_text' => $headerType === 'text' ? ($data['header_text'] ?? $existing?->header_text) : null,
            'header_media_url' => in_array($headerType, ['image', 'video', 'document'], true)
                ? ($data['header_media_url'] ?? $existing?->header_media_url) : null,
            'body' => $data['body'] ?? $existing?->body,
            'footer' => array_key_exists('footer', $data) ? $data['footer'] : $existing?->footer,
            'buttons' => array_key_exists('buttons', $data) ? ($data['buttons'] ?: null) : $existing?->buttons,
            'examples' => array_key_exists('examples', $data) ? $data['examples'] : $existing?->examples,
        ], fn ($v) => $v !== null) + ['header_text' => null, 'header_media_url' => null];
    }

    private function assertExamples(WhatsAppTemplate $template): void
    {
        if (! $template->account?->isCloudApi()) {
            return;
        }

        $examples = $template->examples ?? [];
        if ($template->bodyVariableCount() > 0 && count($examples['body'] ?? []) !== $template->bodyVariableCount()) {
            throw new WhatsAppException('TEMPLATE_EXAMPLES_REQUIRED', 'Meta requires one example value per body variable (examples.body)', 422);
        }
    }

    private function guard(callable $fn): array
    {
        try {
            return $fn();
        } catch (MetaApiException $e) {
            $mapped = $this->errors->map($e->metaCode, $e->getMessage(), $e->httpStatus);
            throw new WhatsAppException($mapped['code'], $mapped['message'], 422, ['meta_code' => $e->metaCode]);
        }
    }
}
