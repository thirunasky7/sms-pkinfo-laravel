<?php

namespace App\Services\WhatsApp\Meta;

use App\Models\WhatsAppTemplate;

/**
 * Converts a local template + params into Meta's message and template-management payloads.
 *
 * Message params shape:
 *   ['header' => ['text var'], 'header_media_url' => 'https://…', 'body' => ['v1', 'v2'],
 *    'buttons' => [['index' => 0, 'sub_type' => 'url', 'value' => 'abc']]]
 */
class TemplatePayloadBuilder
{
    public function messageComponents(WhatsAppTemplate $template, array $params): array
    {
        $components = [];

        if ($template->header_type === 'text' && ! empty($params['header'])) {
            $components[] = [
                'type' => 'header',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($params['header'])),
            ];
        } elseif (in_array($template->header_type, ['image', 'video', 'document'], true)) {
            $link = $params['header_media_url'] ?? $template->header_media_url;
            if ($link) {
                $components[] = [
                    'type' => 'header',
                    'parameters' => [['type' => $template->header_type, $template->header_type => ['link' => $link]]],
                ];
            }
        }

        if (! empty($params['body'])) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($params['body'])),
            ];
        }

        foreach ($params['buttons'] ?? [] as $button) {
            $subType = $button['sub_type'] ?? 'url';
            $parameter = match ($subType) {
                'quick_reply' => ['type' => 'payload', 'payload' => (string) ($button['value'] ?? '')],
                'copy_code' => ['type' => 'coupon_code', 'coupon_code' => (string) ($button['value'] ?? '')],
                default => ['type' => 'text', 'text' => (string) ($button['value'] ?? '')],
            };
            $components[] = [
                'type' => 'button',
                'sub_type' => $subType,
                'index' => (string) ((int) ($button['index'] ?? 0)),
                'parameters' => [$parameter],
            ];
        }

        return $components;
    }

    /**
     * Payload for POST /{waba_id}/message_templates.
     */
    public function definition(WhatsAppTemplate $template): array
    {
        $components = [];
        $examples = $template->examples ?? [];

        if ($template->header_type === 'text' && $template->header_text) {
            $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $template->header_text];
            if ($template->headerVariableCount() > 0 && ! empty($examples['header'])) {
                $header['example'] = ['header_text' => array_values((array) $examples['header'])];
            }
            $components[] = $header;
        } elseif (in_array($template->header_type, ['image', 'video', 'document'], true)) {
            $header = ['type' => 'HEADER', 'format' => strtoupper($template->header_type)];
            if (! empty($examples['header_handle'])) {
                $header['example'] = ['header_handle' => [(string) $examples['header_handle']]];
            }
            $components[] = $header;
        }

        $body = ['type' => 'BODY', 'text' => $template->body];
        if ($template->bodyVariableCount() > 0 && ! empty($examples['body'])) {
            $body['example'] = ['body_text' => [array_values((array) $examples['body'])]];
        }
        $components[] = $body;

        if ($template->footer) {
            $components[] = ['type' => 'FOOTER', 'text' => $template->footer];
        }

        if (! empty($template->buttons)) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => array_map(function (array $b) {
                    $type = strtoupper($b['type'] ?? 'QUICK_REPLY');
                    $button = ['type' => $type, 'text' => (string) ($b['text'] ?? '')];
                    if ($type === 'URL') {
                        $button['url'] = (string) ($b['url'] ?? '');
                        if (! empty($b['example'])) {
                            $button['example'] = [(string) $b['example']];
                        }
                    }
                    if ($type === 'PHONE_NUMBER') {
                        $button['phone_number'] = (string) ($b['phone_number'] ?? '');
                    }
                    if ($type === 'COPY_CODE') {
                        $button = ['type' => 'COPY_CODE', 'example' => (string) ($b['example'] ?? '')];
                    }

                    return $button;
                }, $template->buttons),
            ];
        }

        return [
            'name' => $template->name,
            'language' => $template->language,
            'category' => strtoupper($template->category),
            'components' => $components,
        ];
    }

    /**
     * Map a template returned by Meta into local columns.
     */
    public function fromMeta(array $remote): array
    {
        $attrs = [
            'header_type' => 'none',
            'header_text' => null,
            'body' => '',
            'footer' => null,
            'buttons' => null,
        ];

        foreach ($remote['components'] ?? [] as $component) {
            switch (strtoupper($component['type'] ?? '')) {
                case 'HEADER':
                    $format = strtolower($component['format'] ?? 'text');
                    $attrs['header_type'] = in_array($format, ['text', 'image', 'video', 'document'], true) ? $format : 'none';
                    $attrs['header_text'] = $component['text'] ?? null;
                    break;
                case 'BODY':
                    $attrs['body'] = $component['text'] ?? '';
                    break;
                case 'FOOTER':
                    $attrs['footer'] = mb_substr((string) ($component['text'] ?? ''), 0, 60);
                    break;
                case 'BUTTONS':
                    $attrs['buttons'] = array_map(fn ($b) => array_filter([
                        'type' => strtolower($b['type'] ?? 'quick_reply'),
                        'text' => $b['text'] ?? null,
                        'url' => $b['url'] ?? null,
                        'phone_number' => $b['phone_number'] ?? null,
                    ]), $component['buttons'] ?? []);
                    break;
            }
        }

        return $attrs;
    }
}
