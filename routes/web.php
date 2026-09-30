<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('foundation/pages/welcome', [
        'appVersion' => (string) config('app.version'),
    ]);
})->name('home');
