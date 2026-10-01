<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Audit
{
    /**
     * Keys that must never be persisted into audit metadata.
     */
    private const REDACT = ['access_token', 'token', 'secret', 'app_secret', 'code', 'password', 'authorization'];

    public static function log(?int $actorId, string $action, ?Model $target = null, array $meta = []): void
    {
        if (! $actorId) {
            return;
        }

        try {
            AuditLog::create([
                'admin_id' => $actorId,
                'action' => $action,
                'target_type' => $target ? $target::class : null,
                'target_id' => $target?->getKey(),
                'meta' => self::redact($meta),
                'ip' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (in_array(strtolower((string) $key), self::REDACT, true)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
