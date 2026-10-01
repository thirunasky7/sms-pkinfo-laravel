<?php

namespace App\Services\WhatsApp\Meta;

use App\Models\WhatsAppMessage;

class MetaStatusMapper
{
    /**
     * Meta webhook status -> gateway status. Unknown statuses (e.g. "deleted",
     * "warning") return null and are ignored.
     */
    public function map(string $metaStatus): ?string
    {
        return match (strtolower($metaStatus)) {
            'sent' => WhatsAppMessage::STATUS_SENT,
            'delivered' => WhatsAppMessage::STATUS_DELIVERED,
            'read', 'played' => WhatsAppMessage::STATUS_READ,
            'failed' => WhatsAppMessage::STATUS_FAILED,
            default => null,
        };
    }

    public function mapTemplateStatus(string $metaStatus): string
    {
        return match (strtoupper($metaStatus)) {
            // FLAGGED/REINSTATED templates remain sendable.
            'APPROVED', 'REINSTATED', 'FLAGGED' => 'approved',
            'REJECTED' => 'rejected',
            'PAUSED' => 'paused',
            'DISABLED', 'DELETED', 'PENDING_DELETION' => 'disabled',
            default => 'pending',
        };
    }
}
