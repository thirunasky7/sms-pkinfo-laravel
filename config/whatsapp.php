<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Gateway
    |--------------------------------------------------------------------------
    |
    | All secrets come from the environment. Nothing in this file should ever
    | be returned in an API response or written to logs.
    |
    */

    'enabled' => env('WHATSAPP_ENABLED', true),

    'require_https' => env('WHATSAPP_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

    'connectors' => ['device', 'cloud_api'],

    'max_retries' => (int) env('WHATSAPP_MAX_RETRIES', 3),

    'retry_backoff_seconds' => [30, 120, 600],

    'device' => [
        // Device connector accounts are marked disconnected after this many
        // minutes without a WhatsApp sync from the phone.
        'offline_after_minutes' => (int) env('WHATSAPP_DEVICE_OFFLINE_MINUTES', 5),

        // Outgoing messages waiting on a disconnected device fail after this long.
        'fail_pending_after_minutes' => (int) env('WHATSAPP_DEVICE_FAIL_PENDING_MINUTES', 60),

        'sync_batch_size' => 10,
    ],

    'media' => [
        'disk' => env('WHATSAPP_MEDIA_DISK', 'public'),
        'max_kb' => (int) env('WHATSAPP_MEDIA_MAX_KB', 16384),
        'mimes' => 'jpg,jpeg,png,webp,mp4,3gp,mp3,ogg,aac,amr,m4a,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'config_id' => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),
        'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'timeout' => (int) env('META_HTTP_TIMEOUT', 20),
        // Refresh user-scoped tokens when they expire within this many days.
        'refresh_before_days' => 7,
    ],

    'webhook_events' => [
        'whatsapp.message.sent',
        'whatsapp.message.delivered',
        'whatsapp.message.read',
        'whatsapp.message.failed',
        'whatsapp.message.received',
    ],

    'api_scopes' => [
        'whatsapp:send',
        'whatsapp:read',
        'whatsapp:templates',
    ],

];
