<?php

use App\Http\Controllers\ConnectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LockController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

// === Profil & App Lock (pintu masuk, tanpa login) ===
Route::get('/profil', [ProfileController::class, 'index'])->name('profiles.index');
Route::post('/profil', [ProfileController::class, 'store'])->name('profiles.store');
Route::post('/profil/switch', [ProfileController::class, 'switch'])->name('profiles.switch');

Route::get('/lock', [LockController::class, 'show'])->name('lock.show');
Route::post('/lock', [LockController::class, 'unlock'])->name('lock.unlock');
Route::post('/lock/now', [LockController::class, 'lockNow'])->name('lock.now');

// === OAuth "Connect with Facebook" ===
Route::get('/connect', [ConnectController::class, 'start'])->name('connect.start')->middleware('admin.session');
Route::get('/auth/facebook/callback', [ConnectController::class, 'callback'])->name('connect.callback');
Route::get('/connect/pages', [ConnectController::class, 'pages'])->name('connect.pages')->middleware('admin.session');
Route::post('/connect/select', [ConnectController::class, 'select'])->name('connect.select')->middleware('admin.session');
Route::post('/connect/cancel', [ConnectController::class, 'cancel'])->name('connect.cancel')->middleware('admin.session');
Route::post('/connect/disconnect', [ConnectController::class, 'disconnect'])->name('connect.disconnect')->middleware('admin.session');

// === Dashboard & fitur (butuh profil + lock aktif terpenuhi) ===
Route::middleware('admin.session')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live', [DashboardController::class, 'live'])->name('dashboard.live');

    Route::prefix('rules')->name('rules.')->group(function () {
        Route::get('/', [RuleController::class, 'index'])->name('index');
        Route::post('/', [RuleController::class, 'store'])->name('store');
        Route::post('{rule}', [RuleController::class, 'update'])->name('update');
        Route::post('{rule}/toggle', [RuleController::class, 'toggle'])->name('toggle');
        Route::delete('{rule}', [RuleController::class, 'destroy'])->name('destroy');
    });

    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/media-preview', [LogController::class, 'mediaPreview'])->name('logs.media-preview');
    Route::post('/logs/{comment}/retry', [LogController::class, 'retry'])->name('logs.retry');

    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/poll-now', [SettingController::class, 'pollNow'])->name('settings.poll-now');
    Route::post('/settings/test', [SettingController::class, 'test'])->name('settings.test');
    Route::post('/settings/accounts/{account}/media', [SettingController::class, 'updateMedia'])->name('settings.account-media');
    Route::post('/settings/accounts/{account}/resolve-media', [SettingController::class, 'resolveMedia'])->name('settings.resolve-media');

    Route::post('/settings/app-lock', [LockController::class, 'pinStore'])->name('app-lock.store');
});
