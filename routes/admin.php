<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Config\Http\Controllers\AdminAuthController;
use App\Modules\Config\Http\Controllers\AdminConfigController;
use App\Modules\Identity\Http\Controllers\AdminIdentityController;
use App\Modules\Config\Http\Middleware\EnsureAdmin;
use App\Modules\Config\Http\Middleware\RequireAdminPermission;

// Every route here inherits Laravel's 'web' stack: secure session, CSRF, cookies.
Route::prefix('admin')->name('orbit.admin.')->group(function (): void {
    // Serve the small admin assets from the existing Laravel app on Hostinger.
    // public_html uses a separate front controller; no duplicated public files or symlinks.
    Route::get('/assets/{file}', function (string $file) {
        abort_unless(in_array($file, ['admin.css', 'admin.js', 'orbit-logo.png'], true), 404);
        return response()->file(public_path('assets/orbit-admin/'.$file), [
            'Content-Type' => match ($file) { 'admin.css' => 'text/css; charset=UTF-8', 'admin.js' => 'application/javascript; charset=UTF-8', default => 'image/png' },
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    })->where('file', 'admin\\.(css|js)|orbit-logo\\.png')->name('asset');
    Route::get('/login', [AdminAuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'password'])->middleware('throttle:8,1')->name('password');
    Route::get('/mfa', [AdminAuthController::class, 'mfaForm'])->name('mfa');
    Route::post('/mfa', [AdminAuthController::class, 'verifyMfa'])->middleware('throttle:8,1')->name('mfa.verify');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
    Route::middleware([EnsureAdmin::class])->group(function (): void {
        Route::get('/users', [AdminIdentityController::class, 'index'])
            ->middleware(RequireAdminPermission::class.':users.read')->name('users.index');
        Route::get('/users/{user}', [AdminIdentityController::class, 'show'])
            ->middleware(RequireAdminPermission::class.':users.read')->name('users.show');
        Route::post('/users/{user}/status', [AdminIdentityController::class, 'updateStatus'])
            ->middleware(RequireAdminPermission::class.':users.manage')->name('users.status');
        Route::delete('/users/{user}', [AdminIdentityController::class, 'destroy'])
            ->middleware([RequireAdminPermission::class.':users.delete', 'throttle:6,1'])->name('users.destroy');
    });
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
