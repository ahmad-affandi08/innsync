<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Presentation\Http\Controllers\AuthenticatedSessionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\MfaController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordConfirmationController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PropertySelectionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\UserSessionController;
use App\Shared\Infrastructure\Localization\SetLocaleController;
use App\Shared\Infrastructure\Offline\SyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::post('/locale', SetLocaleController::class)->middleware('throttle:60,1')->name('locale.update');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware(['auth', 'auth.session', 'active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/confirm-password', [PasswordConfirmationController::class, 'show'])
        ->name('password.confirm');
    Route::post('/confirm-password', [PasswordConfirmationController::class, 'store'])
        ->middleware('throttle:login')
        ->name('password.confirm.store');

    Route::get('/mfa/setup', [MfaController::class, 'setup'])
        ->middleware('password.confirm')
        ->name('mfa.setup');
    Route::post('/mfa/setup', [MfaController::class, 'begin'])
        ->middleware(['password.confirm', 'throttle:mfa'])
        ->name('mfa.begin');
    Route::post('/mfa/confirm', [MfaController::class, 'confirm'])
        ->middleware(['password.confirm', 'throttle:mfa'])
        ->name('mfa.confirm');
    Route::get('/mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [MfaController::class, 'verify'])
        ->middleware('throttle:mfa')
        ->name('mfa.verify');

    Route::middleware('mfa')->group(function (): void {
        Route::get('/properties/select', [PropertySelectionController::class, 'create'])
            ->name('properties.select');
        Route::post('/properties/select', [PropertySelectionController::class, 'store'])
            ->name('properties.select.store');

        Route::get('/account/sessions', [UserSessionController::class, 'index'])
            ->name('security.sessions');
        Route::put('/account/password', [PasswordController::class, 'update'])
            ->middleware(['password.confirm', 'throttle:sensitive'])
            ->name('security.password.update');
        Route::delete('/account/sessions/others', [UserSessionController::class, 'destroyOthers'])
            ->middleware('password.confirm')
            ->name('security.sessions.destroy-others');
        Route::delete('/account/sessions/{sessionId}', [UserSessionController::class, 'destroy'])
            ->middleware('password.confirm')
            ->where('sessionId', '[A-Za-z0-9]+')
            ->name('security.sessions.destroy');
    });
});

Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->get('/', function (Request $request) {
    return Inertia::render('foundation/pages/welcome', [
        'appVersion' => (string) config('app.version'),
        'userName' => (string) $request->user()->name,
        'activePropertyId' => (string) $request->session()->get('auth.active_property_id'),
    ]);
})->name('home');

// Offline queue synchronization (TASK-FND-017): signed in, MFA satisfied, property selected. Each item is
// authorized again on the server and applied idempotently by its client-generated operation ID.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property', 'throttle:sync'])
    ->post('/sync/batch', SyncController::class)
    ->name('sync.batch');

// Field test for the offline queue (TASK-FND-017): staff and IT use it to prove a device can work offline.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])
    ->get('/offline-check', fn () => Inertia::render('foundation/pages/offline-check'))
    ->name('offline.check');
