<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRule extends Model
{
    public const CHANNELS = ['whatsapp', 'sms', 'any'];

    public const TRIGGERS = ['incoming', 'failed'];

    public const MATCH_TYPES = ['any', 'keyword', 'contains', 'exact', 'regex'];

    /**
     * action => triggers it may be used with
     */
    public const ACTIONS = [
        'auto_reply' => ['incoming'],
        'forward_webhook' => ['incoming', 'failed'],
        'forward_whatsapp' => ['incoming'],
        'forward_sms' => ['incoming'],
        'opt_out' => ['incoming'],
        'opt_in' => ['incoming'],
        'sms_fallback' => ['failed'],
        'route_whatsapp' => ['failed'],
    ];

    protected $fillable = [
        'user_id',
        'name',
        'channel',
        'trigger',
        'match_type',
        'keywords',
        'action',
        'action_config',
        'priority',
        'stop_processing',
        'is_active',
        'trigger_count',
        'last_triggered_at',
    ];

    protected $casts = [
        'keywords' => 'array',
        'action_config' => 'array',
        'priority' => 'integer',
        'stop_processing' => 'boolean',
        'is_active' => 'boolean',
        'trigger_count' => 'integer',
        'last_triggered_at' => 'datetime',
    ];

    protected $hidden = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matches(?string $text): bool
    {
        $text = trim((string) $text);
        $keywords = array_filter(array_map(fn ($k) => trim((string) $k), $this->keywords ?? []), 'strlen');

        return match ($this->match_type) {
            'any' => true,
            'keyword' => $text !== '' && in_array(
                mb_strtolower(preg_replace('/[^\p{L}\p{N}]+$/u', '', preg_split('/\s+/u', $text)[0])),
                array_map('mb_strtolower', $keywords),
                true
            ),
            'exact' => in_array(mb_strtolower($text), array_map('mb_strtolower', $keywords), true),
            'contains' => collect($keywords)->contains(fn ($k) => mb_stripos($text, $k) !== false),
            'regex' => collect($keywords)->contains(fn ($p) => $this->safeRegex($p, $text)),
            default => false,
        };
    }

    private function safeRegex(string $pattern, string $text): bool
    {
        $delimited = '/'.str_replace('/', '\/', $pattern).'/iu';
        $result = @preg_match($delimited, $text);

        return $result === 1;
    }
}
