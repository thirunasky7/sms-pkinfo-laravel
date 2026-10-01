<?php

use App\Http\Controllers\Web\AuthWebController;
use App\Http\Controllers\Web\CustomerAdminController;
use App\Http\Controllers\Web\SuperAdminController;
use App\Http\Controllers\Web\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthWebController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthWebController::class, 'login']);
});

Route::post('/logout', [AuthWebController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'role:super_admin'])->prefix('super')->name('super.')->group(function () {
    Route::get('/dashboard', [SuperAdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/users', [SuperAdminController::class, 'users'])->name('users');
    Route::post('/users', [SuperAdminController::class, 'storeUser'])->name('users.store');
    Route::patch('/users/{user}/status', [SuperAdminController::class, 'updateUserStatus'])->name('users.status');
    Route::get('/devices', [SuperAdminController::class, 'devices'])->name('devices');
    Route::patch('/devices/{device}/status', [SuperAdminController::class, 'updateDeviceStatus'])->name('devices.status');
    Route::get('/messages', [SuperAdminController::class, 'messages'])->name('messages');
    Route::get('/plans', [SuperAdminController::class, 'plans'])->name('plans');
    Route::post('/plans', [SuperAdminController::class, 'storePlan'])->name('plans.store');
});

Route::middleware(['auth', 'role:customer_admin,sub_admin'])->prefix('customer')->name('customer.')->group(function () {
    Route::get('/dashboard', [CustomerAdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/devices', [CustomerAdminController::class, 'devices'])->name('devices');
    Route::post('/devices/pairing', [CustomerAdminController::class, 'createPairing'])->name('devices.pairing');
    Route::patch('/devices/{device}', [CustomerAdminController::class, 'renameDevice'])->name('devices.rename');
    Route::delete('/devices/{device}', [CustomerAdminController::class, 'removeDevice'])->name('devices.remove');
    Route::get('/messages', [CustomerAdminController::class, 'messages'])->name('messages');
    Route::get('/compose', [CustomerAdminController::class, 'compose'])->name('compose');
    Route::post('/compose', [CustomerAdminController::class, 'sendSms'])->name('compose.send');
    Route::post('/bulk', [CustomerAdminController::class, 'bulkUpload'])->name('bulk');
    Route::get('/api', [CustomerAdminController::class, 'apiIntegration'])->name('api');
    Route::post('/api/keys', [CustomerAdminController::class, 'createApiKey'])->name('api.keys');
    Route::post('/api/keys/{apiKey}/secret', [CustomerAdminController::class, 'showApiSecret'])->middleware('throttle:30,1')->name('api.keys.secret');
    Route::post('/api/keys/{apiKey}/regenerate', [CustomerAdminController::class, 'regenerateApiSecret'])->name('api.keys.regenerate');
    Route::delete('/api/keys/{apiKey}', [CustomerAdminController::class, 'revokeApiKey'])->name('api.keys.revoke');
    Route::post('/api/webhooks', [CustomerAdminController::class, 'storeWebhook'])->name('api.webhooks');
    Route::get('/tickets', [CustomerAdminController::class, 'tickets'])->name('tickets');
    Route::post('/tickets', [CustomerAdminController::class, 'storeTicket'])->name('tickets.store');

    Route::prefix('whatsapp')->name('whatsapp.')->controller(WhatsAppController::class)->group(function () {
        Route::get('/', 'overview')->name('overview');
        Route::get('/connection', 'connection')->name('connection');
        Route::post('/cloud/embedded-signup', 'embeddedSignup')->middleware('throttle:10,1')->name('cloud.embedded');
        Route::post('/cloud/manual', 'manualConnect')->middleware('throttle:10,1')->name('cloud.manual');
        Route::post('/accounts/{account}/default', 'setDefault')->name('accounts.default');
        Route::delete('/accounts/{account}', 'revoke')->name('accounts.revoke');
        Route::get('/messages', 'messages')->name('messages');
        Route::post('/messages', 'send')->name('messages.send');
        Route::post('/messages/{message}/retry', 'retry')->name('messages.retry');
        Route::get('/incoming', 'incoming')->name('incoming');
        Route::get('/templates', 'templates')->name('templates');
        Route::post('/templates', 'storeTemplate')->name('templates.store');
        Route::post('/templates/sync', 'syncTemplates')->name('templates.sync');
        Route::get('/templates/{template}/edit', 'editTemplate')->name('templates.edit');
        Route::put('/templates/{template}', 'updateTemplate')->name('templates.update');
        Route::delete('/templates/{template}', 'destroyTemplate')->name('templates.destroy');
        Route::get('/rules', 'rules')->name('rules');
        Route::post('/rules', 'storeRule')->name('rules.store');
        Route::patch('/rules/{rule}/toggle', 'toggleRule')->name('rules.toggle');
        Route::delete('/rules/{rule}', 'destroyRule')->name('rules.destroy');
        Route::delete('/opt-outs/{optOut}', 'removeOptOut')->name('optouts.destroy');
        Route::get('/webhooks', 'webhooks')->name('webhooks');
        Route::post('/webhooks', 'storeWebhook')->name('webhooks.store');
        Route::delete('/webhooks/{webhook}', 'destroyWebhook')->name('webhooks.destroy');
        Route::get('/docs', 'docs')->name('docs');
    });
});
