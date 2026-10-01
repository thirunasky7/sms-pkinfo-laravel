<?php

namespace App\Services\WhatsApp\Connectors;

use App\Models\WhatsAppMessage;

interface Connector
{
    public function send(WhatsAppMessage $message): ConnectorResult;
}
