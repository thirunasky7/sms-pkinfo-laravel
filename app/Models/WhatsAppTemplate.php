<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    public const CATEGORIES = ['marketing', 'utility', 'authentication'];

    public const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'paused', 'disabled'];

    public const HEADER_TYPES = ['none', 'text', 'image', 'video', 'document'];

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'user_id',
        'whatsapp_account_id',
        'name',
        'language',
        'category',
        'status',
        'provider_template_id',
        'header_type',
        'header_text',
        'header_media_url',
        'body',
        'footer',
        'buttons',
        'examples',
        'rejection_reason',
        'usage_count',
        'last_synced_at',
    ];

    protected $casts = [
        'buttons' => 'array',
        'examples' => 'array',
        'usage_count' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Number of {{n}} placeholders in the body.
     */
    public function bodyVariableCount(): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $this->body, $m);

        return $m[1] ? (int) max($m[1]) : 0;
    }

    public function headerVariableCount(): int
    {
        if ($this->header_type !== 'text') {
            return 0;
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $this->header_text, $m);

        return $m[1] ? (int) max($m[1]) : 0;
    }

    /**
     * Render the template locally with body params (used for device connector
     * and for message history display).
     */
    public function render(array $bodyParams = [], array $headerParams = []): string
    {
        $replace = function (string $text, array $params): string {
            return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($m) use ($params) {
                return (string) ($params[(int) $m[1] - 1] ?? $m[0]);
            }, $text);
        };

        $parts = [];
        if ($this->header_type === 'text' && $this->header_text) {
            $parts[] = $replace($this->header_text, $headerParams);
        }
        $parts[] = $replace($this->body, $bodyParams);
        if ($this->footer) {
            $parts[] = $this->footer;
        }

        return implode("\n\n", $parts);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->whatsapp_account_id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'status' => $this->status,
            'header_type' => $this->header_type,
            'header_text' => $this->header_text,
            'header_media_url' => $this->header_media_url,
            'body' => $this->body,
            'footer' => $this->footer,
            'buttons' => $this->buttons ?? [],
            'body_variables' => $this->bodyVariableCount(),
            'header_variables' => $this->headerVariableCount(),
            'rejection_reason' => $this->rejection_reason,
            'usage_count' => $this->usage_count,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
