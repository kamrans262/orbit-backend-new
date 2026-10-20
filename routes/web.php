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

// Dedicated browser-only admin routes; no consumer API session reuse.
require __DIR__.'/admin.php';
