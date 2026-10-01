<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'key',
        'secret_hash',
        'rate_limit',
        'scopes',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Keys created before scopes existed (null/empty scopes) keep full access.
     * Keys with explicit scopes must list the scope, a prefix wildcard, or "*".
     */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes;

        if (empty($scopes)) {
            return true;
        }

        if (in_array('*', $scopes, true) || in_array($scope, $scopes, true)) {
            return true;
        }

        $prefix = explode(':', $scope)[0];

        return in_array($prefix.':*', $scopes, true);
    }
}
