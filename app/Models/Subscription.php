<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'starts_at',
        'expires_at',
        'status',
        'sms_used',
        'grace_ends_at',
        'provider',
        'provider_subscription_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'grace_ends_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function allowsSend(): bool
    {
        if (! in_array($this->status, ['active', 'grace'], true)) {
            return false;
        }

        if ($this->status === 'grace' && $this->grace_ends_at && $this->grace_ends_at->isPast()) {
            return false;
        }

        if ($this->expires_at->isPast() && $this->status === 'active') {
            return false;
        }

        return $this->sms_used < $this->plan->sms_limit;
    }
}
