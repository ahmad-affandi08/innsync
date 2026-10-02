<?php

declare(strict_types=1);

use App\Modules\FrontOffice\Presentation\Http\Controllers\AvailabilityController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\CashierController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\ChecklistController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\FeedbackController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\FolioController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\GuestRequestController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\InventoryController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\LogbookController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\NightAuditController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\RateChangeController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\ReservationController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\RoomBoardController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\StayController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\StayFeePolicyController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\ChecklistController as HousekeepingChecklistController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\HousekeepingController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\LinenController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ApprovalController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ApprovalPolicyController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\AuthenticatedSessionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\MfaController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordConfirmationController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PropertySelectionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ReconfirmController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\UserSessionController;
use App\Modules\Laundry\Presentation\Http\Controllers\LaundryController;
use App\Modules\Property\Presentation\Http\Controllers\BookingPolicyController;
use App\Modules\Property\Presentation\Http\Controllers\ChargeSchemeController;
use App\Modules\Property\Presentation\Http\Controllers\PropertySettingsController;
use App\Modules\Property\Presentation\Http\Controllers\RatePlanController;
use App\Modules\Property\Presentation\Http\Controllers\RoomCatalogController;
use App\Modules\Reporting\Presentation\Http\Controllers\DashboardController;
use App\Modules\Reporting\Presentation\Http\Controllers\ObligationController;
use App\Modules\Reporting\Presentation\Http\Controllers\ReportController;
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
    Route::get('/policies', [ApprovalPolicyController::class, 'index'])->name('approvals.policies');
    Route::post('/policies', [ApprovalPolicyController::class, 'define'])->middleware('password.confirm')->name('approvals.policies.define');
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
    Route::get('/policies', [BookingPolicyController::class, 'index'])->name('property.policies');
    Route::post('/policies', [BookingPolicyController::class, 'define'])->middleware('password.confirm')->name('property.policies.store');
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
    Route::get('/reservations/{id}/penalty', [ReservationController::class, 'penalty'])->where('id', $id)->name('front-office.reservations.penalty');
    Route::post('/reservations/{id}/guarantee', [ReservationController::class, 'guarantee'])->where('id', $id)->name('front-office.reservations.guarantee');
    // Changing the room price of a booked reservation; a discount over the policy threshold needs an approval (FR-FO-013).
    Route::get('/reservations/{id}/rate-preview', [RateChangeController::class, 'preview'])->where('id', $id)->name('front-office.reservations.rate-preview');
    Route::post('/reservations/{id}/rate-approval', [RateChangeController::class, 'requestApproval'])->where('id', $id)->middleware('idempotent')->name('front-office.reservations.rate-approval');
    Route::post('/reservations/{id}/rate', [RateChangeController::class, 'change'])->where('id', $id)->middleware('password.confirm')->name('front-office.reservations.rate');
    Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel'])->where('id', $id)->name('front-office.reservations.cancel');
    Route::post('/reservations/{id}/no-show', [ReservationController::class, 'noShow'])->where('id', $id)->name('front-office.reservations.no-show');

    // Check-in, in-house guests and check-out (FR-FO-010 to FR-FO-016). The identity photo is served only through the audited download.
    Route::get('/stays', [StayController::class, 'index'])->name('front-office.stays');
    Route::get('/stays/{id}', [StayController::class, 'show'])->where('id', $id)->name('front-office.stays.show');
    Route::get('/stays/{id}/id-photo', [StayController::class, 'photo'])->where('id', $id)->name('front-office.stays.photo');
    Route::post('/stays/{id}/id-photo', [StayController::class, 'attachPhoto'])->where('id', $id)->middleware('throttle:bookings')->name('front-office.stays.photo.store');
    Route::get('/stays/{id}/move-options', [StayController::class, 'moveOptions'])->where('id', $id)->name('front-office.stays.move-options');
    Route::post('/stays/{id}/move', [StayController::class, 'move'])->where('id', $id)->name('front-office.stays.move');
    Route::get('/stays/{id}/extension-quote', [StayController::class, 'extensionQuote'])->where('id', $id)->name('front-office.stays.extension-quote');
    Route::post('/stays/{id}/extend', [StayController::class, 'extend'])->where('id', $id)->name('front-office.stays.extend');
    Route::post('/stays/{id}/corrections', [StayController::class, 'correct'])->where('id', $id)->middleware('password.confirm')->name('front-office.stays.correct');
    Route::post('/stays/{id}/corrections/approval', [StayController::class, 'correctionApproval'])->where('id', $id)->middleware('idempotent')->name('front-office.stays.correction-approval');
    Route::post('/stays/{id}/check-out', [StayController::class, 'checkOut'])->where('id', $id)->name('front-office.stays.check-out');
    Route::get('/reservations/{id}/check-in', [StayController::class, 'checkInForm'])->where('id', $id)->name('front-office.check-in');
    Route::post('/reservations/{id}/guest-lookup', [StayController::class, 'lookup'])->where('id', $id)->middleware('throttle:bookings')->name('front-office.check-in.lookup');
    Route::post('/reservations/{id}/check-in', [StayController::class, 'checkIn'])->where('id', $id)->middleware(['idempotent', 'throttle:bookings'])->name('front-office.check-in.store');

    Route::get('/room-board', RoomBoardController::class)->name('front-office.room-board');

    // Night audit closes the business date (FR-FO-028, BR-001). Running it needs a recent password confirmation and an Idempotency-Key.
    Route::get('/night-audit', [NightAuditController::class, 'index'])->name('front-office.night-audit');
    Route::get('/night-audit/{date}', [NightAuditController::class, 'show'])->where('date', '\d{4}-\d{2}-\d{2}')->name('front-office.night-audit.show');
    Route::post('/night-audit', [NightAuditController::class, 'run'])->middleware(['password.confirm', 'idempotent'])->name('front-office.night-audit.run');

    // Folios (FR-FO-020, -024, -025, -029). Money that leaves or is corrected needs a recent password confirmation and, by policy, an approval.
    Route::post('/reservations/{id}/folios', [FolioController::class, 'open'])->where('id', $id)->name('front-office.folios.open');
    Route::get('/folios/{id}', [FolioController::class, 'show'])->where('id', $id)->name('front-office.folios.show');
    Route::get('/folios/{id}/bill', [FolioController::class, 'bill'])->where('id', $id)->name('front-office.folios.bill');
    Route::post('/folios/{id}/charges', [FolioController::class, 'charge'])->where('id', $id)->middleware(['idempotent', 'throttle:bookings'])->name('front-office.folios.charge');
    Route::post('/folios/{id}/late-charges', [FolioController::class, 'lateCharge'])->where('id', $id)->middleware(['idempotent', 'throttle:bookings'])->name('front-office.folios.late-charge');
    Route::post('/folios/{id}/payments', [FolioController::class, 'pay'])->where('id', $id)->middleware(['idempotent', 'throttle:bookings'])->name('front-office.folios.pay');
    Route::get('/folios/{id}/transfer-targets', [FolioController::class, 'transferTargets'])->where('id', $id)->name('front-office.folios.transfer-targets');
    Route::get('/stay-fees', [StayFeePolicyController::class, 'index'])->name('front-office.stay-fees');
    Route::post('/stay-fees', [StayFeePolicyController::class, 'define'])->name('front-office.stay-fees.define');
    Route::post('/stays/{id}/time-fees', [StayController::class, 'decideTimeFee'])->where('id', $id)->name('front-office.stays.time-fees');
    Route::post('/postings/{id}/transfer', [FolioController::class, 'transfer'])->where('id', $id)->name('front-office.postings.transfer');
    Route::post('/postings/{id}/reversal-request', [FolioController::class, 'requestReversal'])->where('id', $id)->middleware('idempotent')->name('front-office.postings.reversal-request');
    Route::post('/folios/{id}/refund-request', [FolioController::class, 'requestRefund'])->where('id', $id)->middleware('idempotent')->name('front-office.folios.refund-request');
    Route::middleware('password.confirm')->group(function () use ($id): void {
        Route::post('/folios/{id}/close', [FolioController::class, 'close'])->where('id', $id)->name('front-office.folios.close');
        Route::post('/postings/{id}/reverse', [FolioController::class, 'reverse'])->where('id', $id)->name('front-office.postings.reverse');
        Route::post('/folios/{id}/refund', [FolioController::class, 'refund'])->where('id', $id)->name('front-office.folios.refund');
    });

    // Guest requests (FR-FO-030): what in-house guests ask for, by department, and where each one stands.
    Route::get('/requests', [GuestRequestController::class, 'index'])->name('front-office.requests');
    Route::post('/requests', [GuestRequestController::class, 'open'])->middleware(['idempotent', 'throttle:bookings'])->name('front-office.requests.store');
    Route::post('/requests/{id}/start', [GuestRequestController::class, 'start'])->where('id', $id)->name('front-office.requests.start');
    Route::post('/requests/{id}/complete', [GuestRequestController::class, 'complete'])->where('id', $id)->name('front-office.requests.complete');
    Route::post('/requests/{id}/cancel', [GuestRequestController::class, 'cancel'])->where('id', $id)->name('front-office.requests.cancel');

    // Front desk checklists (FR-FO-032, FR-FO-033) and the handover log between shifts (FR-FO-034).
    Route::get('/checklists', [ChecklistController::class, 'index'])->name('front-office.checklists');
    Route::get('/checklists/templates', [ChecklistController::class, 'templates'])->name('front-office.checklists.templates');
    Route::get('/checklists/performance', [ChecklistController::class, 'performance'])->name('front-office.checklists.performance');
    Route::post('/checklists/templates', [ChecklistController::class, 'define'])->name('front-office.checklists.define');
    Route::post('/checklists/{template}/items/{item}/complete', [ChecklistController::class, 'complete'])->where(['template' => $id, 'item' => 'i[0-9]{1,2}'])->name('front-office.checklists.complete');
    Route::get('/logbook', [LogbookController::class, 'index'])->name('front-office.logbook');
    Route::post('/logbook', [LogbookController::class, 'write'])->middleware('throttle:bookings')->name('front-office.logbook.write');
    Route::post('/logbook/read', [LogbookController::class, 'read'])->name('front-office.logbook.read');

    // Guest comments and complaints (FR-FO-031).
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('front-office.feedback');
    Route::get('/feedback/{id}', [FeedbackController::class, 'show'])->where('id', $id)->name('front-office.feedback.show');
    Route::post('/feedback', [FeedbackController::class, 'record'])->middleware(['idempotent', 'throttle:bookings'])->name('front-office.feedback.store');
    Route::post('/feedback/{id}/assign', [FeedbackController::class, 'assign'])->where('id', $id)->name('front-office.feedback.assign');
    Route::post('/feedback/{id}/note', [FeedbackController::class, 'note'])->where('id', $id)->name('front-office.feedback.note');
    Route::post('/feedback/{id}/start', [FeedbackController::class, 'start'])->where('id', $id)->name('front-office.feedback.start');
    Route::post('/feedback/{id}/resolve', [FeedbackController::class, 'resolve'])->where('id', $id)->name('front-office.feedback.resolve');
    Route::post('/feedback/{id}/close', [FeedbackController::class, 'close'])->where('id', $id)->name('front-office.feedback.close');
    Route::post('/feedback/{id}/reopen', [FeedbackController::class, 'reopen'])->where('id', $id)->name('front-office.feedback.reopen');

    // Cashier shifts (FR-FO-036): own shift, review of all shifts, and the property switch that requires an open shift to take money.
    Route::get('/cashier', [CashierController::class, 'mine'])->name('front-office.cashier');
    Route::get('/cashier/shifts', [CashierController::class, 'index'])->name('front-office.cashier.shifts');
    Route::get('/cashier/shifts/{id}', [CashierController::class, 'show'])->where('id', $id)->name('front-office.cashier.shifts.show');
    Route::post('/cashier/shifts', [CashierController::class, 'open'])->name('front-office.cashier.open');
    Route::post('/cashier/shifts/{id}/drops', [CashierController::class, 'drop'])->where('id', $id)->middleware('idempotent')->name('front-office.cashier.drop');
    Route::post('/cashier/shifts/{id}/close', [CashierController::class, 'close'])->where('id', $id)->name('front-office.cashier.close');
    Route::post('/cashier/settings', [CashierController::class, 'settings'])->middleware('password.confirm')->name('front-office.cashier.settings');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('front-office.inventory');
    Route::middleware('password.confirm')->group(function () use ($id): void {
        Route::post('/room-blocks', [InventoryController::class, 'block'])->name('front-office.room-blocks.store');
        Route::post('/room-blocks/{id}/release', [InventoryController::class, 'releaseBlock'])->where('id', $id)->name('front-office.room-blocks.release');
        Route::post('/holds', [InventoryController::class, 'hold'])->name('front-office.holds.store');
        Route::post('/holds/{id}/release', [InventoryController::class, 'releaseHold'])->where('id', $id)->name('front-office.holds.release');
        Route::post('/overbooking/{typeId}', [InventoryController::class, 'allowance'])->where('typeId', $id)->name('front-office.overbooking.set');
    });
});

// Housekeeping (FR-HK-001 to FR-HK-004, FR-HK-007, FR-HK-018): the status of rooms, work for attendants, inspection by supervisors.
// Permissions are enforced in the application service.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('housekeeping')->group(function (): void {
    $id = '[0-9A-Za-z]{26}';
    Route::get('/', [HousekeepingController::class, 'board'])->name('housekeeping.board');
    Route::get('/my-rooms', [HousekeepingController::class, 'myRooms'])->name('housekeeping.my-rooms');
    Route::get('/rooms/{id}', [HousekeepingController::class, 'room'])->where('id', $id)->name('housekeeping.rooms.show');
    Route::post('/tasks', [HousekeepingController::class, 'requestService'])->name('housekeeping.tasks.store');
    Route::post('/flags', [HousekeepingController::class, 'raiseFlag'])->name('housekeeping.flags.store');
    Route::post('/flags/{id}/end', [HousekeepingController::class, 'endFlag'])->where('id', $id)->name('housekeeping.flags.end');
    Route::get('/rooms/{id}/flags', [HousekeepingController::class, 'flagHistory'])->where('id', $id)->name('housekeeping.rooms.flags');
    Route::post('/tasks/{id}/assign', [HousekeepingController::class, 'assign'])->where('id', $id)->name('housekeeping.tasks.assign');
    Route::post('/tasks/{id}/cancel', [HousekeepingController::class, 'cancel'])->where('id', $id)->name('housekeeping.tasks.cancel');
    Route::post('/tasks/{id}/start', [HousekeepingController::class, 'start'])->where('id', $id)->name('housekeeping.tasks.start');
    Route::post('/tasks/{id}/finish', [HousekeepingController::class, 'finish'])->where('id', $id)->name('housekeeping.tasks.finish');
    Route::post('/rooms/{id}/inspections', [HousekeepingController::class, 'inspect'])->where('id', $id)->name('housekeeping.rooms.inspect');
    Route::post('/findings/{id}/resolve', [HousekeepingController::class, 'resolveFinding'])->where('id', $id)->name('housekeeping.findings.resolve');
    Route::post('/findings/{id}/waive', [HousekeepingController::class, 'waiveFinding'])->where('id', $id)->name('housekeeping.findings.waive');
    Route::post('/settings', [HousekeepingController::class, 'settings'])->name('housekeeping.settings');

    // Linen and amenities (FR-HK-009 to FR-HK-011, FR-LDY-007): counted transfers, in-transit balances, usage per room.
    Route::get('/linen', [LinenController::class, 'index'])->name('housekeeping.linen');
    Route::get('/linen/usage', [LinenController::class, 'usage'])->name('housekeeping.linen.usage');
    Route::post('/linen/usage', [LinenController::class, 'recordUsage'])->name('housekeeping.linen.usage.store');
    Route::post('/linen/items', [LinenController::class, 'storeItem'])->name('housekeeping.linen.items.store');
    Route::post('/linen/items/{id}/active', [LinenController::class, 'itemActive'])->where('id', $id)->name('housekeeping.linen.items.active');
    Route::post('/linen/transfers', [LinenController::class, 'send'])->middleware('idempotent')->name('housekeeping.linen.transfers.store');
    Route::post('/linen/transfers/{id}/receive', [LinenController::class, 'receive'])->where('id', $id)->name('housekeeping.linen.transfers.receive');
    Route::post('/linen/transfers/{id}/cancel', [LinenController::class, 'cancel'])->where('id', $id)->name('housekeeping.linen.transfers.cancel');

    // Checklists per room and public area (FR-HK-005).
    Route::get('/checklists', [HousekeepingChecklistController::class, 'index'])->name('housekeeping.checklists');
    Route::get('/checklists/templates', [HousekeepingChecklistController::class, 'templates'])->name('housekeeping.checklists.templates');
    Route::post('/checklists/templates', [HousekeepingChecklistController::class, 'define'])->name('housekeeping.checklists.define');
    Route::get('/checklists/performance', [HousekeepingChecklistController::class, 'performance'])->name('housekeeping.checklists.performance');
    Route::get('/checklists/{template}/detail', [HousekeepingChecklistController::class, 'detail'])->where('template', $id)->name('housekeeping.checklists.detail');
    Route::post('/checklists/{template}/complete', [HousekeepingChecklistController::class, 'complete'])->where('template', $id)->name('housekeeping.checklists.complete');
});

// Guest laundry (FR-HK-020 to FR-HK-024, FR-LDY-001 to FR-LDY-004, FR-LDY-011): hand-over by housekeeping, counting and processing by
// the laundry, delivery back to the room. Permissions are enforced in the application service.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('laundry')->group(function (): void {
    $id = '[0-9A-Za-z]{26}';
    Route::get('/', [LaundryController::class, 'index'])->name('laundry.queue');
    Route::get('/new', [LaundryController::class, 'create'])->name('laundry.new');
    Route::get('/prices', [LaundryController::class, 'prices'])->name('laundry.prices');
    Route::post('/prices', [LaundryController::class, 'addPrice'])->name('laundry.prices.store');
    Route::post('/prices/{id}', [LaundryController::class, 'updatePrice'])->where('id', $id)->name('laundry.prices.update');
    Route::post('/treatments', [LaundryController::class, 'addTreatment'])->name('laundry.treatments.store');
    Route::post('/treatments/{id}', [LaundryController::class, 'updateTreatment'])->where('id', $id)->name('laundry.treatments.update');
    Route::post('/orders', [LaundryController::class, 'store'])->middleware(['idempotent', 'throttle:bookings'])->name('laundry.orders.store');
    Route::get('/orders/{id}', [LaundryController::class, 'show'])->where('id', $id)->name('laundry.orders.show');
    Route::post('/orders/{id}/receive', [LaundryController::class, 'receive'])->where('id', $id)->name('laundry.orders.receive');
    Route::post('/orders/{id}/advance', [LaundryController::class, 'advance'])->where('id', $id)->name('laundry.orders.advance');
    Route::post('/orders/{id}/ready', [LaundryController::class, 'ready'])->where('id', $id)->name('laundry.orders.ready');
    Route::post('/orders/{id}/deliver', [LaundryController::class, 'deliver'])->where('id', $id)->name('laundry.orders.deliver');
    Route::post('/orders/{id}/cancel', [LaundryController::class, 'cancel'])->where('id', $id)->name('laundry.orders.cancel');
});

// Dashboard and reports (FR-DSH-*, FR-RPT-*, FR-FO-040 to -042): read-only. Permissions are enforced in the application services;
// an export of personal data needs a stated purpose.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::prefix('reports')->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])->name('reports');
        Route::get('/movements', [ReportController::class, 'movements'])->name('reports.movements');
        Route::get('/movements/export', [ReportController::class, 'exportMovements'])->name('reports.movements.export');
        Route::get('/performance', [ReportController::class, 'performance'])->name('reports.performance');
        Route::get('/performance/export', [ReportController::class, 'exportPerformance'])->name('reports.performance.export');
        Route::get('/flash', [ReportController::class, 'flash'])->name('reports.flash');
        Route::get('/flash/export', [ReportController::class, 'exportFlash'])->name('reports.flash.export');
        Route::get('/housekeeping', [ReportController::class, 'housekeeping'])->name('reports.housekeeping');
        Route::get('/housekeeping/export', [ReportController::class, 'exportHousekeeping'])->name('reports.housekeeping.export');
        Route::get('/obligations', [ObligationController::class, 'index'])->name('reports.obligations');
        Route::post('/obligations/settings', [ObligationController::class, 'saveSettings'])->name('reports.obligations.settings');
        Route::post('/obligations/filings', [ObligationController::class, 'markReported'])->name('reports.obligations.filings');
        Route::get('/laundry', [ReportController::class, 'laundry'])->name('reports.laundry');
        Route::get('/laundry/export', [ReportController::class, 'exportLaundry'])->name('reports.laundry.export');
        Route::get('/payments', [ReportController::class, 'payments'])->name('reports.payments');
        Route::get('/payments/export', [ReportController::class, 'exportPayments'])->name('reports.payments.export');
        Route::get('/registrations', [ReportController::class, 'registrations'])->name('reports.registrations');
        Route::get('/registrations/export', [ReportController::class, 'exportRegistrations'])->name('reports.registrations.export');
        Route::get('/foreign-guests', [ReportController::class, 'foreignGuests'])->name('reports.foreign');
        Route::get('/foreign-guests/export', [ReportController::class, 'exportForeignGuests'])->name('reports.foreign.export');
        Route::get('/audit', [ReportController::class, 'audit'])->name('reports.audit');
    });
});
