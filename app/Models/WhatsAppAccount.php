<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    public const CONNECTOR_DEVICE = 'device';

    public const CONNECTOR_CLOUD_API = 'cloud_api';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'user_id',
        'name',
        'connector_type',
        'status',
        'device_id',
        'phone_number',
        'display_name',
        'business_id',
        'waba_id',
        'phone_number_id',
        'access_token',
        'token_expires_at',
        'is_default',
        'last_error',
        'meta',
        'connected_at',
        'disconnected_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'is_default' => 'boolean',
        'meta' => 'array',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'meta',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('user_id', $accountId);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->whereIn('status', [self::STATUS_CONNECTED, self::STATUS_DISCONNECTED]);
    }

    public function isDevice(): bool
    {
        return $this->connector_type === self::CONNECTOR_DEVICE;
    }

    public function isCloudApi(): bool
    {
        return $this->connector_type === self::CONNECTOR_CLOUD_API;
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && $this->revoked_at === null;
    }

    public function hasToken(): bool
    {
        return ! empty($this->getRawOriginal('access_token'));
    }

    /**
     * Safe public representation. Tokens and raw provider metadata are never included.
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'connector_type' => $this->connector_type,
            'status' => $this->status,
            'phone_number' => $this->phone_number,
            'display_name' => $this->display_name,
            'is_default' => $this->is_default,
            'device_id' => $this->device_id,
            'waba_id' => $this->waba_id,
            'phone_number_id' => $this->phone_number_id,
            'token_configured' => $this->isCloudApi() ? $this->hasToken() : null,
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'disconnected_at' => $this->disconnected_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
