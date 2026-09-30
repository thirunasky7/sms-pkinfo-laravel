<?php

use App\Http\Controllers\Web\AuthWebController;
use App\Http\Controllers\Web\CustomerAdminController;
use App\Http\Controllers\Web\SuperAdminController;
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
    Route::post('/api/webhooks', [CustomerAdminController::class, 'storeWebhook'])->name('api.webhooks');
    Route::get('/tickets', [CustomerAdminController::class, 'tickets'])->name('tickets');
    Route::post('/tickets', [CustomerAdminController::class, 'storeTicket'])->name('tickets.store');
});
