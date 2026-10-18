<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Dedicated browser-only admin routes; no consumer API session reuse.
require __DIR__.'/admin.php';
