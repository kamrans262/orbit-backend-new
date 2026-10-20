<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Browser fallback for a short-lived, single-use M4 friend invite.
// Actual token redemption ALWAYS happens through an authenticated mobile API.
Route::get('/invite/friend/{token}', function (string $token) {
    abort_unless(preg_match('/^[A-Za-z0-9_-]{43}$/D', $token), 404);
    return response()->view('orbit-social.friend-invite', ['token' => $token])
        ->header('Cache-Control', 'no-store, private')
        ->header('Referrer-Policy', 'no-referrer')
        ->header('X-Robots-Tag', 'noindex, nofollow')
        ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
})->where('token', '[A-Za-z0-9_-]{43}')->name('orbit.social.invite');

// Public app association files contain ONLY signed-app identifiers, not user data.
// Fail closed until release engineering configures the verified native app IDs.
Route::get('/.well-known/apple-app-site-association', function () {
    $appId = trim((string) config('orbit_social.ios_app_id'));
    abort_unless((bool) preg_match('/^[A-Z0-9]{10}\.[A-Za-z0-9.-]+$/D', $appId), 404);
    return response()->json([
        'applinks' => [
            'details' => [[
                'appIDs' => [$appId],
                'components' => [['/' => '/invite/friend/*', 'comment' => 'Orbit friend invitations']],
            ]],
        ],
    ])->header('Content-Type', 'application/json')
      ->header('Cache-Control', 'public, max-age=3600');
});
Route::get('/.well-known/assetlinks.json', function () {
    $package = trim((string) config('orbit_social.android_package'));
    $fingerprint = trim((string) config('orbit_social.android_sha256'));
    abort_unless((bool) preg_match('/^[A-Za-z][A-Za-z0-9_.]+$/D', $package)
        && (bool) preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/iD', $fingerprint), 404);
    return response()->json([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => $package,
            'sha256_cert_fingerprints' => [strtoupper($fingerprint)]],
    ]])->header('Cache-Control', 'public, max-age=3600');
});

// Dedicated browser-only admin routes; no consumer API session reuse.
require __DIR__.'/admin.php';
