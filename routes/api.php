<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WhatsApp\AccountController as WhatsAppAccountController;
use App\Http\Controllers\Api\V1\WhatsApp\ConnectionController as WhatsAppConnectionController;
use App\Http\Controllers\Api\V1\WhatsApp\DeviceWhatsAppController;
use App\Http\Controllers\Api\V1\WhatsApp\MessageController as WhatsAppMessageController;
use App\Http\Controllers\Api\V1\WhatsApp\MetaWebhookController;
use App\Http\Controllers\Api\V1\WhatsApp\TemplateController as WhatsAppTemplateController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::middleware('role:customer_admin,sub_admin,super_admin')->group(function () {
            Route::post('devices/pairing-token', [DeviceController::class, 'createPairingToken']);
            Route::get('messages', [MessageController::class, 'index']);
            Route::get('api-keys', [ApiKeyController::class, 'index']);
            Route::post('api-keys', [ApiKeyController::class, 'store']);
            Route::delete('api-keys/{id}', [ApiKeyController::class, 'revoke']);
            Route::get('webhooks', [WebhookController::class, 'index']);
            Route::post('webhooks', [WebhookController::class, 'store']);
            Route::delete('webhooks/{id}', [WebhookController::class, 'destroy']);

            Route::get('whatsapp/accounts', [WhatsAppAccountController::class, 'index']);
            Route::patch('whatsapp/accounts/{id}', [WhatsAppAccountController::class, 'update']);
            Route::delete('whatsapp/accounts/{id}', [WhatsAppAccountController::class, 'destroy']);
        });
    });

    Route::post('devices/register', [DeviceController::class, 'register']);

    Route::middleware(['auth:sanctum', 'device'])->group(function () {
        Route::post('devices/heartbeat', [DeviceController::class, 'heartbeat']);
        Route::patch('devices/{id}', [DeviceController::class, 'updateStatus']);
        Route::post('devices/{id}/messages/{messageId}/status', [MessageController::class, 'updateStatus']);
        Route::post('devices/{id}/messages/incoming', [MessageController::class, 'incoming']);

        Route::post('devices/{id}/whatsapp/connect', [DeviceWhatsAppController::class, 'connect']);
        Route::post('devices/{id}/whatsapp/disconnect', [DeviceWhatsAppController::class, 'disconnect']);
        Route::post('devices/{id}/whatsapp/sync', [DeviceWhatsAppController::class, 'sync']);
        Route::post('devices/{id}/whatsapp/messages/{messageId}/status', [DeviceWhatsAppController::class, 'updateStatus']);
        Route::post('devices/{id}/whatsapp/messages/incoming', [DeviceWhatsAppController::class, 'incoming']);
    });

    Route::middleware(['api.key', 'api.key.throttle', 'subscription'])->group(function () {
        Route::post('messages/send', [MessageController::class, 'send'])->middleware('api.scope:messages:send');
    });

    // Meta calls this endpoint directly: no API key, authenticated by verify token / signature.
    Route::prefix('whatsapp/webhooks')
        ->middleware('https')
        ->withoutMiddleware(ThrottleRequests::class.':api')
        ->group(function () {
            Route::get('meta', [MetaWebhookController::class, 'verify']);
            Route::post('meta', [MetaWebhookController::class, 'receive']);
        });

    Route::prefix('whatsapp')->middleware(['https', 'api.key', 'api.key.throttle'])->group(function () {
        Route::get('connection', [WhatsAppConnectionController::class, 'show'])->middleware('api.scope:whatsapp:read');

        Route::middleware('api.scope:whatsapp:read')->group(function () {
            Route::get('messages', [WhatsAppMessageController::class, 'index']);
            Route::get('message/{messageId}', [WhatsAppMessageController::class, 'show']);
            Route::post('check-number', [WhatsAppMessageController::class, 'checkNumber']);
        });

        Route::middleware(['api.scope:whatsapp:send', 'whatsapp.subscription'])->group(function () {
            Route::post('send', [WhatsAppMessageController::class, 'send']);
            Route::post('template/send', [WhatsAppTemplateController::class, 'send']);
        });

        Route::middleware('api.scope:whatsapp:templates')->group(function () {
            Route::get('templates', [WhatsAppTemplateController::class, 'index']);
            Route::post('templates', [WhatsAppTemplateController::class, 'store']);
            Route::post('templates/sync', [WhatsAppTemplateController::class, 'sync']);
            Route::get('templates/{id}', [WhatsAppTemplateController::class, 'show']);
            Route::put('templates/{id}', [WhatsAppTemplateController::class, 'update']);
            Route::delete('templates/{id}', [WhatsAppTemplateController::class, 'destroy']);
        });
    });
});
