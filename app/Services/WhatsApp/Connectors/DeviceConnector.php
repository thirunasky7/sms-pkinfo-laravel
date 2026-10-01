<?php

namespace App\Services\WhatsApp\Connectors;

use App\Models\WhatsAppMessage;

/**
 * The phone pulls queued messages via POST /devices/{id}/whatsapp/sync, so
 * "sending" here only validates the link; delivery happens on the device.
 */
class DeviceConnector implements Connector
{
    public function send(WhatsAppMessage $message): ConnectorResult
    {
        $account = $message->account;

        if (! $account->device_id || ! $account->device) {
            return ConnectorResult::failed('DEVICE_NOT_LINKED', 'No device is linked to this WhatsApp account', false);
        }

        if (in_array($account->device->status, ['disabled'], true)) {
            return ConnectorResult::failed('DEVICE_DISABLED', 'The linked device is disabled', false);
        }

        return ConnectorResult::awaitingDevice();
    }
}
