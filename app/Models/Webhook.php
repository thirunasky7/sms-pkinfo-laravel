<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Webhook extends Model
{
    protected $fillable = [
        'user_id',
        'event_type',
        'url',
        'secret',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'secret',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
