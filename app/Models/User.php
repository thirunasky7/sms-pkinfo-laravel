<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'parent_id',
        'phone',
        'company',
        'two_factor_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_enabled' => 'boolean',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['active', 'grace'])
            ->latestOfMany();
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function pairingTokens(): HasMany
    {
        return $this->hasMany(DevicePairingToken::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function subAdmins(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isCustomerAdmin(): bool
    {
        return in_array($this->role, ['customer_admin', 'sub_admin'], true);
    }

    public function ownsAccountId(): int
    {
        return $this->role === 'sub_admin' && $this->parent_id
            ? (int) $this->parent_id
            : (int) $this->id;
    }
}
