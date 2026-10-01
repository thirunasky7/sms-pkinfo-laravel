<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactOptOut extends Model
{
    protected $fillable = [
        'user_id',
        'channel',
        'phone',
        'source',
        'reason',
    ];

    public static function isOptedOut(int $accountId, string $channel, string $phone): bool
    {
        return static::query()
            ->where('user_id', $accountId)
            ->whereIn('channel', [$channel, 'all'])
            ->where('phone', $phone)
            ->exists();
    }
}
