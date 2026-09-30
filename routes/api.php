<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\WebhookController;
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
        });
    });

    Route::post('devices/register', [DeviceController::class, 'register']);

    Route::middleware(['auth:sanctum', 'device'])->group(function () {
        Route::post('devices/heartbeat', [DeviceController::class, 'heartbeat']);
        Route::patch('devices/{id}', [DeviceController::class, 'updateStatus']);
        Route::post('devices/{id}/messages/{messageId}/status', [MessageController::class, 'updateStatus']);
        Route::post('devices/{id}/messages/incoming', [MessageController::class, 'incoming']);
    });

    Route::middleware(['api.key', 'api.key.throttle', 'subscription'])->group(function () {
        Route::post('messages/send', [MessageController::class, 'send']);
    });
});
