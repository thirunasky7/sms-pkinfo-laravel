<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Device extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'imei',
        'sim_number',
        'model',
        'android_version',
        'fcm_token',
        'status',
        'daily_send_limit',
        'last_sync_at',
        'api_token_hint',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isAvailable(): bool
    {
        return in_array($this->status, ['online', 'pending'], true)
            || ($this->last_sync_at && $this->last_sync_at->gt(now()->subMinutes(5)));
    }
}
