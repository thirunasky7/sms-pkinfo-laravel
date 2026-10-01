<?php

namespace App\Services\WhatsApp\Connectors;

use App\Models\WhatsAppAccount;
use InvalidArgumentException;

class ConnectorFactory
{
    public function for(WhatsAppAccount $account): Connector
    {
        return match ($account->connector_type) {
            WhatsAppAccount::CONNECTOR_DEVICE => app(DeviceConnector::class),
            WhatsAppAccount::CONNECTOR_CLOUD_API => app(CloudApiConnector::class),
            default => throw new InvalidArgumentException("Unknown connector [{$account->connector_type}]"),
        };
    }
}
