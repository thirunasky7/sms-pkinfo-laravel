<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WhatsAppMessage extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECEIVED = 'received';

    public const TYPES = ['text', 'image', 'video', 'audio', 'document', 'template'];

    /**
     * Status ordering so late or out-of-order provider callbacks never move a
     * message backwards (e.g. "delivered" arriving after "read").
     */
    public const STATUS_RANK = [
        self::STATUS_QUEUED => 0,
        self::STATUS_SENDING => 1,
        self::STATUS_SENT => 2,
        self::STATUS_DELIVERED => 3,
        self::STATUS_READ => 4,
    ];

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'message_id',
        'user_id',
        'whatsapp_account_id',
        'device_id',
        'api_key_id',
        'whatsapp_template_id',
        'direction',
        'connector_type',
        'message_type',
        'sender',
        'recipient',
        'body',
        'media_url',
        'media_mime',
        'media_filename',
        'template_params',
        'status',
        'retry_count',
        'last_attempt_at',
        'failure_reason',
        'error_code',
        'provider_message_id',
        'external_id',
        'idempotency_key',
        'meta',
        'queued_at',
        'scheduled_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'received_at',
    ];

    protected $casts = [
        'template_params' => 'array',
        'meta' => 'array',
        'retry_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'queued_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WhatsAppMessage $message) {
            $message->message_id ??= 'wam_'.Str::lower((string) Str::ulid());
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_READ, self::STATUS_FAILED, self::STATUS_RECEIVED], true);
    }

    public function canTransitionTo(string $status): bool
    {
        if ($status === self::STATUS_FAILED) {
            return ! in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_READ], true);
        }

        if ($this->status === self::STATUS_FAILED) {
            // A retry re-queues a failed message; provider success after a failure is also honoured.
            return true;
        }

        return (self::STATUS_RANK[$status] ?? 0) >= (self::STATUS_RANK[$this->status] ?? 0);
    }

    public function toApiArray(): array
    {
        return [
            'message_id' => $this->message_id,
            'direction' => $this->direction,
            'connector_type' => $this->connector_type,
            'message_type' => $this->message_type,
            'account_id' => $this->whatsapp_account_id,
            'device_id' => $this->device_id,
            'sender' => $this->sender,
            'recipient' => $this->recipient,
            'body' => $this->body,
            'media_url' => $this->media_url,
            'media_mime' => $this->media_mime,
            'template' => $this->whatsapp_template_id ? [
                'id' => $this->whatsapp_template_id,
                'name' => $this->template?->name,
                'language' => $this->template?->language,
                'params' => $this->template_params,
            ] : null,
            'status' => $this->status,
            'retry_count' => $this->retry_count,
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'error_code' => $this->error_code,
            'provider_message_id' => $this->provider_message_id,
            'external_id' => $this->external_id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'timestamp' => $this->created_at?->toIso8601String(),
        ];
    }
}
