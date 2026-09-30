<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'direction',
        'sender',
        'recipient',
        'body',
        'status',
        'retry_count',
        'external_id',
        'error_message',
        'meta',
        'queued_at',
        'sent_at',
        'delivered_at',
        'scheduled_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
