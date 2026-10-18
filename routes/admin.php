<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Config\Http\Controllers\AdminAuthController;
use App\Modules\Config\Http\Controllers\AdminConfigController;
use App\Modules\Config\Http\Middleware\EnsureAdmin;
use App\Modules\Config\Http\Middleware\RequireAdminPermission;

// Every route here inherits Laravel's 'web' stack: secure session, CSRF, cookies.
Route::prefix('admin')->name('orbit.admin.')->group(function (): void {
    Route::get('/login', [AdminAuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'password'])->middleware('throttle:8,1')->name('password');
    Route::get('/mfa', [AdminAuthController::class, 'mfaForm'])->name('mfa');
    Route::post('/mfa', [AdminAuthController::class, 'verifyMfa'])->middleware('throttle:8,1')->name('mfa.verify');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
    Route::middleware([EnsureAdmin::class, RequireAdminPermission::class])->group(function (): void {
        Route::get('/', [AdminConfigController::class, 'dashboard'])->name('dashboard');
        Route::get('/flags/new', [AdminConfigController::class, 'flagForm'])->name('flags.new');
        Route::post('/flags', [AdminConfigController::class, 'storeFlag'])->name('flags.store');
        Route::get('/flags/{flag}/edit', [AdminConfigController::class, 'flagForm'])->name('flags.edit');
        Route::post('/flags/{flag}', [AdminConfigController::class, 'storeFlag'])->name('flags.update');
        Route::get('/flags/{flag}/preview', [AdminConfigController::class, 'preview'])->name('flags.preview');
        Route::get('/config/new', [AdminConfigController::class, 'configForm'])->name('config.new');
        Route::post('/config', [AdminConfigController::class, 'storeConfig'])->name('config.store');
        Route::get('/config/{config}/edit', [AdminConfigController::class, 'configForm'])->name('config.edit');
        Route::post('/config/{config}', [AdminConfigController::class, 'storeConfig'])->name('config.update');
        Route::post('/revisions/{type}/{key}/rollback', [AdminConfigController::class, 'rollback'])->name('rollback');
    });
});
