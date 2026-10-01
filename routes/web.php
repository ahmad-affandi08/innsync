<?php

declare(strict_types=1);

use App\Modules\FrontOffice\Presentation\Http\Controllers\AvailabilityController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\InventoryController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\ReservationController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ApprovalController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\AuthenticatedSessionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\MfaController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordConfirmationController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PropertySelectionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ReconfirmController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\UserSessionController;
use App\Modules\Property\Presentation\Http\Controllers\ChargeSchemeController;
use App\Modules\Property\Presentation\Http\Controllers\PropertySettingsController;
use App\Modules\Property\Presentation\Http\Controllers\RatePlanController;
use App\Modules\Property\Presentation\Http\Controllers\RoomCatalogController;
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

// After a lapsed password confirmation (HTTP 423) a screen sends the person here and they return to the page they were on.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'password.confirm'])->get('/reconfirm', ReconfirmController::class)->name('reconfirm');

// Maker-checker approval (TASK-FND-018, NFR-06, BR-004). Deciding is sensitive: it needs a recent password
// confirmation (NFR-22). Requests are opened by modules through the ApprovalGate contract, not from the browser.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('approvals')->group(function (): void {
    Route::get('/', [ApprovalController::class, 'index'])->name('approvals.index');
    // The screen sends people here when the confirmation window has lapsed; they return to the inbox afterwards.
    Route::get('/confirm', fn () => redirect()->route('approvals.index'))->middleware('password.confirm')->name('approvals.confirm');
    Route::get('/{id}', [ApprovalController::class, 'show'])->where('id', '[0-9A-Za-z]{26}')->name('approvals.show');
    Route::post('/{id}/approve', [ApprovalController::class, 'approve'])
        ->middleware(['password.confirm', 'throttle:approvals'])->where('id', '[0-9A-Za-z]{26}')->name('approvals.approve');
    Route::post('/{id}/reject', [ApprovalController::class, 'reject'])
        ->middleware(['password.confirm', 'throttle:approvals'])->where('id', '[0-9A-Za-z]{26}')->name('approvals.reject');
    Route::post('/{id}/cancel', [ApprovalController::class, 'cancel'])
        ->middleware('throttle:approvals')->where('id', '[0-9A-Za-z]{26}')->name('approvals.cancel');
});

// Property configuration (TASK-FO-007 groundwork): settings, business date, room types and rooms.
// Permissions are enforced in the application services; the middleware only establishes who and where.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('property')->group(function (): void {
    Route::get('/settings', [PropertySettingsController::class, 'show'])->name('property.settings');
    Route::put('/settings', [PropertySettingsController::class, 'update'])->middleware('password.confirm')->name('property.settings.update');
    Route::post('/settings/business-date', [PropertySettingsController::class, 'initializeBusinessDate'])->middleware('password.confirm')->name('property.business-date.initialize');

    Route::get('/rooms', [RoomCatalogController::class, 'index'])->name('property.rooms');
    Route::post('/room-types', [RoomCatalogController::class, 'storeType'])->name('property.room-types.store');
    Route::put('/room-types/{id}', [RoomCatalogController::class, 'updateType'])->where('id', '[0-9A-Za-z]{26}')->name('property.room-types.update');
    Route::post('/room-types/{id}/active', [RoomCatalogController::class, 'typeActive'])->where('id', '[0-9A-Za-z]{26}')->name('property.room-types.active');
    Route::post('/rooms', [RoomCatalogController::class, 'storeRoom'])->name('property.rooms.store');
    Route::put('/rooms/{id}', [RoomCatalogController::class, 'updateRoom'])->where('id', '[0-9A-Za-z]{26}')->name('property.rooms.update');
    Route::post('/rooms/{id}/active', [RoomCatalogController::class, 'roomActive'])->where('id', '[0-9A-Za-z]{26}')->name('property.rooms.active');

    // Rate plans, prices, restrictions (FR-FO-008) and service charge and tax (PRD Q-05). Prices are sensitive: changes need a recent password confirmation.
    $id = '[0-9A-Za-z]{26}';
    Route::get('/rates', [RatePlanController::class, 'index'])->name('property.rates');
    Route::post('/rate-plans/{id}/quote', [RatePlanController::class, 'quote'])->where('id', $id)->name('property.rates.quote');
    Route::middleware('password.confirm')->group(function () use ($id): void {
        Route::post('/rate-plans', [RatePlanController::class, 'storePlan'])->name('property.rate-plans.store');
        Route::put('/rate-plans/{id}', [RatePlanController::class, 'updatePlan'])->where('id', $id)->name('property.rate-plans.update');
        Route::post('/rate-plans/{id}/active', [RatePlanController::class, 'planActive'])->where('id', $id)->name('property.rate-plans.active');
        Route::post('/rate-plans/{id}/prices', [RatePlanController::class, 'addPrice'])->where('id', $id)->name('property.rate-prices.add');
        Route::post('/rate-prices/{id}/reprice', [RatePlanController::class, 'repriceNight'])->where('id', $id)->name('property.rate-prices.reprice');
        Route::post('/rate-prices/{id}/remove', [RatePlanController::class, 'removePrice'])->where('id', $id)->name('property.rate-prices.remove');
        Route::post('/rate-plans/{id}/restrictions', [RatePlanController::class, 'addRestriction'])->where('id', $id)->name('property.rate-restrictions.add');
        Route::post('/rate-restrictions/{id}/remove', [RatePlanController::class, 'removeRestriction'])->where('id', $id)->name('property.rate-restrictions.remove');
        Route::post('/tax', [ChargeSchemeController::class, 'define'])->name('property.tax.define');
    });
    Route::get('/tax', [ChargeSchemeController::class, 'index'])->name('property.tax');
});

// Front Office: availability, reservations, room blocks and holds (FR-FO-002 to FR-FO-007). Permissions are enforced in the
// application services; creating a reservation also needs an Idempotency-Key (NFR-18).
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('front-office')->group(function (): void {
    $id = '[0-9A-Za-z]{26}';
    Route::get('/availability', AvailabilityController::class)->name('front-office.availability');
    Route::get('/reservations', [ReservationController::class, 'index'])->name('front-office.reservations');
    Route::post('/reservations/quote', [ReservationController::class, 'quote'])->name('front-office.reservations.quote');
    Route::post('/reservations', [ReservationController::class, 'store'])->middleware(['idempotent', 'throttle:bookings'])->name('front-office.reservations.store');
    Route::get('/reservations/{id}', [ReservationController::class, 'show'])->where('id', $id)->name('front-office.reservations.show');
    Route::post('/reservations/{id}/confirm', [ReservationController::class, 'confirm'])->where('id', $id)->name('front-office.reservations.confirm');
    Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel'])->where('id', $id)->name('front-office.reservations.cancel');
    Route::post('/reservations/{id}/no-show', [ReservationController::class, 'noShow'])->where('id', $id)->name('front-office.reservations.no-show');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('front-office.inventory');
    Route::middleware('password.confirm')->group(function () use ($id): void {
        Route::post('/room-blocks', [InventoryController::class, 'block'])->name('front-office.room-blocks.store');
        Route::post('/room-blocks/{id}/release', [InventoryController::class, 'releaseBlock'])->where('id', $id)->name('front-office.room-blocks.release');
        Route::post('/holds', [InventoryController::class, 'hold'])->name('front-office.holds.store');
        Route::post('/holds/{id}/release', [InventoryController::class, 'releaseHold'])->where('id', $id)->name('front-office.holds.release');
        Route::post('/overbooking/{typeId}', [InventoryController::class, 'allowance'])->where('typeId', $id)->name('front-office.overbooking.set');
    });
});
