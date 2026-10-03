<?php

declare(strict_types=1);

use App\Modules\Finance\Presentation\Http\Controllers\BudgetController;
use App\Modules\Finance\Presentation\Http\Controllers\CashReconciliationController;
use App\Modules\Finance\Presentation\Http\Controllers\CorrectionController;
use App\Modules\Finance\Presentation\Http\Controllers\ExceptionController;
use App\Modules\Finance\Presentation\Http\Controllers\ExpenseAccountController;
use App\Modules\Finance\Presentation\Http\Controllers\FinanceAuditController;
use App\Modules\Finance\Presentation\Http\Controllers\ManagementReportController;
use App\Modules\Finance\Presentation\Http\Controllers\PayableController;
use App\Modules\Finance\Presentation\Http\Controllers\PayrollDisbursementController;
use App\Modules\Finance\Presentation\Http\Controllers\PettyCashController;
use App\Modules\Finance\Presentation\Http\Controllers\ReceivableController;
use App\Modules\Finance\Presentation\Http\Controllers\RecurringExpenseController;
use App\Modules\Finance\Presentation\Http\Controllers\RevenueController;
use App\Modules\Finance\Presentation\Http\Controllers\SettlementController;
use App\Modules\Finance\Presentation\Http\Controllers\SupplierPaymentController;
use App\Modules\Finance\Presentation\Http\Controllers\TaxController;
use App\Modules\FnbSales\Presentation\Http\Controllers\BillController;
use App\Modules\FnbSales\Presentation\Http\Controllers\DamageReportController as FnbDamageReportController;
use App\Modules\FnbSales\Presentation\Http\Controllers\MinibarController;
use App\Modules\FnbSales\Presentation\Http\Controllers\PaymentController;
use App\Modules\FnbSales\Presentation\Http\Controllers\PriceRuleController;
use App\Modules\FnbSales\Presentation\Http\Controllers\RegisterController;
use App\Modules\FnbSales\Presentation\Http\Controllers\RoomServiceController;
use App\Modules\FnbSales\Presentation\Http\Controllers\SetupController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\AvailabilityController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\CashierController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\ChecklistController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\CompanyController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\FeedbackController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\FolioController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\ForeignPaymentController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\GroupController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\GuestRequestController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\InventoryController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\LogbookController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\NightAuditController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\RateChangeController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\RegistrationCardController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\ReservationController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\RoomBoardController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\StayController;
use App\Modules\FrontOffice\Presentation\Http\Controllers\StayFeePolicyController;
use App\Modules\GuestExperience\Presentation\Http\Controllers\GuestEntryController;
use App\Modules\GuestExperience\Presentation\Http\Controllers\GuestMenuController;
use App\Modules\GuestExperience\Presentation\Http\Controllers\GuestOrderQueueController;
use App\Modules\GuestExperience\Presentation\Http\Controllers\QrPointController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\ChecklistController as HousekeepingChecklistController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\DamageReportController as HousekeepingDamageReportController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\HousekeepingController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\LinenController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\LostFoundController;
use App\Modules\Housekeeping\Presentation\Http\Controllers\ParLevelController;
use App\Modules\HumanResource\Presentation\Http\Controllers\AnnouncementController;
use App\Modules\HumanResource\Presentation\Http\Controllers\AppraisalController;
use App\Modules\HumanResource\Presentation\Http\Controllers\AttendanceAdjustmentController;
use App\Modules\HumanResource\Presentation\Http\Controllers\AttendanceController;
use App\Modules\HumanResource\Presentation\Http\Controllers\ConductController;
use App\Modules\HumanResource\Presentation\Http\Controllers\EmployeeController;
use App\Modules\HumanResource\Presentation\Http\Controllers\EmployeePortalController;
use App\Modules\HumanResource\Presentation\Http\Controllers\LeaveController;
use App\Modules\HumanResource\Presentation\Http\Controllers\PayrollController;
use App\Modules\HumanResource\Presentation\Http\Controllers\PayrollRunController;
use App\Modules\HumanResource\Presentation\Http\Controllers\PayslipController;
use App\Modules\HumanResource\Presentation\Http\Controllers\PerformanceController;
use App\Modules\HumanResource\Presentation\Http\Controllers\RosterController;
use App\Modules\HumanResource\Presentation\Http\Controllers\ServiceChargeController;
use App\Modules\HumanResource\Presentation\Http\Controllers\ShiftSwapController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ApprovalController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ApprovalPolicyController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\AuthenticatedSessionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\MfaController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordConfirmationController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PasswordController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\PropertySelectionController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\ReconfirmController;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\UserSessionController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\GoodsReceiptController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\InventoryCatalogController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\PurchaseOrderController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\PurchaseRequestController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\PurchaseReturnController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\PurchasingReportController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\PurchasingSettingsController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\StockCountController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\StockLotController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\StockMovementController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\StockRequisitionController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\SupplierController;
use App\Modules\InventoryPurchasing\Presentation\Http\Controllers\SupplierInvoiceController;
use App\Modules\Kitchen\Presentation\Http\Controllers\BoardController as KitchenBoardController;
use App\Modules\Kitchen\Presentation\Http\Controllers\DamageReportController as KitchenDamageReportController;
use App\Modules\Kitchen\Presentation\Http\Controllers\MenuReportController;
use App\Modules\Kitchen\Presentation\Http\Controllers\ProductionController;
use App\Modules\Kitchen\Presentation\Http\Controllers\RecipeController;
use App\Modules\Kitchen\Presentation\Http\Controllers\WasteController;
use App\Modules\Laundry\Presentation\Http\Controllers\ClaimController;
use App\Modules\Laundry\Presentation\Http\Controllers\LaundryController;
use App\Modules\Laundry\Presentation\Http\Controllers\SupplyUseController;
use App\Modules\Maintenance\Presentation\Http\Controllers\AssetController;
use App\Modules\Maintenance\Presentation\Http\Controllers\DutyController;
use App\Modules\Maintenance\Presentation\Http\Controllers\PartsController;
use App\Modules\Maintenance\Presentation\Http\Controllers\ReportController as MaintenanceReportController;
use App\Modules\Maintenance\Presentation\Http\Controllers\VendorJobController;
use App\Modules\Maintenance\Presentation\Http\Controllers\WorkOrderController;
use App\Modules\Property\Presentation\Http\Controllers\BookingPolicyController;
use App\Modules\Property\Presentation\Http\Controllers\ChargeSchemeController;
use App\Modules\Property\Presentation\Http\Controllers\PropertySettingsController;
use App\Modules\Property\Presentation\Http\Controllers\RatePlanController;
use App\Modules\Property\Presentation\Http\Controllers\RoomCatalogController;
use App\Modules\Reporting\Presentation\Http\Controllers\DashboardController;
use App\Modules\Reporting\Presentation\Http\Controllers\ExportJobController;
use App\Modules\Reporting\Presentation\Http\Controllers\ObligationController;
use App\Modules\Reporting\Presentation\Http\Controllers\OutletController;
use App\Modules\Reporting\Presentation\Http\Controllers\ReportBuilderController;
use App\Modules\Reporting\Presentation\Http\Controllers\ReportController;
use App\Modules\Reporting\Presentation\Http\Controllers\ReportScheduleController;
use App\Modules\Routines\Presentation\Http\Controllers\RoutineController;
use App\Shared\Infrastructure\Localization\SetLocaleController;
use App\Shared\Infrastructure\Offline\SyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::post('/locale', SetLocaleController::class)->middleware('throttle:60,1')->name('locale.update');

// What a guest reaches with a scanned code (FR-GST): no account, no staff session; a short-lived session of the code, a limit on how fast it can be asked, and nothing but the guest's own orders.
Route::prefix('g')->middleware(['throttle:guest'])->group(function (): void {
    Route::get('/ended', [GuestEntryController::class, 'ended'])->name('guest.ended');
    Route::middleware(['guest.session'])->group(function (): void {
        Route::get('/menu', [GuestMenuController::class, 'menu'])->name('guest.menu');
        Route::get('/orders', [GuestMenuController::class, 'orders'])->name('guest.orders');
        Route::post('/verify', [GuestMenuController::class, 'verify'])->middleware('throttle:guest-write')->name('guest.verify');
        Route::post('/order', [GuestMenuController::class, 'order'])->middleware('throttle:guest-write')->name('guest.order');
    });
    Route::get('/{token}', [GuestEntryController::class, 'enter'])->where('token', '[A-Za-z0-9_-]{32}')->name('guest.enter');
});

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
    Route::get('/stays/{id}/registration-card', [RegistrationCardController::class, 'show'])->where('id', $id)->name('front-office.registration-card');
    Route::post('/stays/{id}/registration-card/sign', [RegistrationCardController::class, 'sign'])->where('id', $id)->name('front-office.registration-card.sign');
    Route::get('/stays/{id}/registration-card/signature', [RegistrationCardController::class, 'signature'])->where('id', $id)->name('front-office.registration-card.signature');
    Route::get('/registration-terms', [RegistrationCardController::class, 'terms'])->name('front-office.registration-terms');
    Route::post('/registration-terms', [RegistrationCardController::class, 'defineTerms'])->name('front-office.registration-terms.define');
    Route::get('/companies', [CompanyController::class, 'index'])->name('front-office.companies');
    Route::post('/companies', [CompanyController::class, 'store'])->name('front-office.companies.store');
    Route::post('/companies/{id}', [CompanyController::class, 'update'])->where('id', $id)->name('front-office.companies.update');
    Route::post('/reservations/{id}/company', [ReservationController::class, 'linkCompany'])->where('id', $id)->name('front-office.reservations.company');
    Route::get('/groups', [GroupController::class, 'index'])->name('front-office.groups');
    Route::post('/groups', [GroupController::class, 'store'])->middleware(['idempotent', 'throttle:bookings'])->name('front-office.groups.store');
    Route::get('/groups/{id}', [GroupController::class, 'show'])->where('id', $id)->name('front-office.groups.show');
    Route::post('/groups/{id}/rooms', [GroupController::class, 'addRooms'])->where('id', $id)->middleware(['idempotent', 'throttle:bookings'])->name('front-office.groups.rooms');
    Route::get('/foreign-currency', [ForeignPaymentController::class, 'index'])->name('front-office.foreign-currency');
    Route::post('/foreign-currency/enabled', [ForeignPaymentController::class, 'enable'])->middleware('password.confirm')->name('front-office.foreign-currency.enable');
    Route::post('/foreign-currency/rates', [ForeignPaymentController::class, 'setRate'])->name('front-office.foreign-currency.rate');
    Route::post('/folios/{id}/foreign-payments', [ForeignPaymentController::class, 'pay'])->where('id', $id)->middleware(['idempotent', 'throttle:bookings'])->name('front-office.folios.foreign-pay');
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
    Route::get('/par-levels', [ParLevelController::class, 'index'])->name('housekeeping.par-levels');
    Route::get('/par-levels/consumption', [ParLevelController::class, 'consumption'])->name('housekeeping.par-levels.consumption');
    Route::post('/par-levels', [ParLevelController::class, 'save'])->name('housekeeping.par-levels.save');
    Route::get('/linen', [LinenController::class, 'index'])->name('housekeeping.linen');
    Route::get('/linen/usage', [LinenController::class, 'usage'])->name('housekeeping.linen.usage');
    Route::post('/linen/usage', [LinenController::class, 'recordUsage'])->name('housekeeping.linen.usage.store');
    Route::post('/linen/items', [LinenController::class, 'storeItem'])->name('housekeeping.linen.items.store');
    Route::post('/linen/items/{id}/active', [LinenController::class, 'itemActive'])->where('id', $id)->name('housekeeping.linen.items.active');
    Route::post('/linen/transfers', [LinenController::class, 'send'])->middleware('idempotent')->name('housekeeping.linen.transfers.store');
    Route::post('/linen/transfers/{id}/receive', [LinenController::class, 'receive'])->where('id', $id)->name('housekeeping.linen.transfers.receive');
    Route::post('/linen/transfers/{id}/cancel', [LinenController::class, 'cancel'])->where('id', $id)->name('housekeeping.linen.transfers.cancel');

    // Lost and found (FR-HK-012).
    Route::get('/damage-reports', [HousekeepingDamageReportController::class, 'index'])->name('housekeeping.damage');
    Route::post('/damage-reports', [HousekeepingDamageReportController::class, 'store'])->name('housekeeping.damage.store');
    Route::get('/lost-found', [LostFoundController::class, 'index'])->name('housekeeping.lost-found');
    Route::post('/lost-found', [LostFoundController::class, 'store'])->name('housekeeping.lost-found.store');
    Route::post('/lost-found/{id}/returned', [LostFoundController::class, 'returned'])->where('id', $id)->name('housekeeping.lost-found.returned');
    Route::post('/lost-found/{id}/disposed', [LostFoundController::class, 'disposed'])->where('id', $id)->name('housekeeping.lost-found.disposed');
    Route::get('/lost-found/{id}/photo', [LostFoundController::class, 'photo'])->where('id', $id)->name('housekeeping.lost-found.photo');

    // Checklists per room and public area (FR-HK-005).
    Route::get('/checklists', [HousekeepingChecklistController::class, 'index'])->name('housekeeping.checklists');
    Route::get('/checklists/templates', [HousekeepingChecklistController::class, 'templates'])->name('housekeeping.checklists.templates');
    Route::post('/checklists/templates', [HousekeepingChecklistController::class, 'define'])->name('housekeeping.checklists.define');
    Route::get('/checklists/performance', [HousekeepingChecklistController::class, 'performance'])->name('housekeeping.checklists.performance');
    Route::get('/checklists/{template}/detail', [HousekeepingChecklistController::class, 'detail'])->where('template', $id)->name('housekeeping.checklists.detail');
    Route::get('/checklists/photo/{completion}', [HousekeepingChecklistController::class, 'photo'])->where('completion', $id)->name('housekeeping.checklists.photo');
    Route::post('/checklists/{template}/complete', [HousekeepingChecklistController::class, 'complete'])->where('template', $id)->name('housekeeping.checklists.complete');
});

// Inventory catalog and stock (FR-INV-001, -002, -003, -009): items with versioned unit conversions, storage locations, minimum and maximum stock,
// and the append-only stock ledger. Permissions are enforced in the application services.
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('inventory')->group(function (): void {
    $id = '[0-9A-Za-z]{26}';
    Route::get('/', fn () => redirect()->route('inventory.stock'));
    Route::get('/items', [InventoryCatalogController::class, 'items'])->name('inventory.items');
    Route::get('/locations', [InventoryCatalogController::class, 'locations'])->name('inventory.locations');
    Route::get('/stock', [InventoryCatalogController::class, 'stock'])->name('inventory.stock');
    Route::get('/valuation', [InventoryCatalogController::class, 'valuation'])->name('inventory.valuation');
    Route::post('/categories', [InventoryCatalogController::class, 'storeCategory'])->name('inventory.categories.store');
    Route::post('/categories/{id}', [InventoryCatalogController::class, 'updateCategory'])->where('id', $id)->name('inventory.categories.update');
    Route::post('/locations', [InventoryCatalogController::class, 'storeLocation'])->name('inventory.locations.store');
    Route::post('/locations/{id}', [InventoryCatalogController::class, 'updateLocation'])->where('id', $id)->name('inventory.locations.update');
    Route::post('/items', [InventoryCatalogController::class, 'storeItem'])->name('inventory.items.store');
    Route::post('/items/{id}', [InventoryCatalogController::class, 'updateItem'])->where('id', $id)->name('inventory.items.update');
    Route::post('/items/{id}/units', [InventoryCatalogController::class, 'addConversion'])->where('id', $id)->name('inventory.items.units');
    Route::post('/stock-limits', [InventoryCatalogController::class, 'setLimits'])->name('inventory.limits');
    Route::post('/stock/opening', [InventoryCatalogController::class, 'postOpening'])->name('inventory.stock.opening');
    Route::get('/lots', [StockLotController::class, 'index'])->name('inventory.lots');
    Route::get('/requisitions', [StockRequisitionController::class, 'index'])->name('inventory.requisitions');
    Route::post('/requisitions', [StockRequisitionController::class, 'store'])->name('inventory.requisitions.store');
    Route::post('/requisitions/{id}/fulfil', [StockRequisitionController::class, 'fulfil'])->where('id', $id)->name('inventory.requisitions.fulfil');
    Route::post('/requisitions/{id}/reject', [StockRequisitionController::class, 'reject'])->where('id', $id)->name('inventory.requisitions.reject');
    Route::post('/requisitions/{id}/cancel', [StockRequisitionController::class, 'cancel'])->where('id', $id)->name('inventory.requisitions.cancel');
    Route::post('/stock/movements', [StockMovementController::class, 'store'])->middleware(['idempotent'])->name('inventory.stock.movements');
    Route::get('/transfers', [StockMovementController::class, 'transfersPage'])->name('inventory.transfers');
    Route::post('/transfers', [StockMovementController::class, 'send'])->middleware(['idempotent'])->name('inventory.transfers.send');
    Route::post('/transfers/{id}/receive', [StockMovementController::class, 'receive'])->where('id', $id)->name('inventory.transfers.receive');
    Route::post('/transfers/{id}/reject', [StockMovementController::class, 'reject'])->where('id', $id)->name('inventory.transfers.reject');
    Route::post('/transfers/{id}/cancel', [StockMovementController::class, 'cancel'])->where('id', $id)->name('inventory.transfers.cancel');
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('inventory.suppliers');
    Route::get('/suppliers/{id}', [SupplierController::class, 'show'])->where('id', $id)->name('inventory.suppliers.show');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('inventory.suppliers.store');
    Route::post('/suppliers/{id}', [SupplierController::class, 'update'])->where('id', $id)->name('inventory.suppliers.update');
    Route::post('/suppliers/{id}/prices', [SupplierController::class, 'addPrice'])->where('id', $id)->name('inventory.suppliers.prices');
    Route::post('/suppliers/{id}/ratings', [SupplierController::class, 'rate'])->where('id', $id)->name('inventory.suppliers.rate');
    Route::get('/requests', [PurchaseRequestController::class, 'index'])->name('inventory.requests');
    Route::get('/requests/{id}', [PurchaseRequestController::class, 'show'])->where('id', $id)->name('inventory.requests.show');
    Route::post('/requests', [PurchaseRequestController::class, 'store'])->name('inventory.requests.store');
    Route::post('/requests/{id}', [PurchaseRequestController::class, 'update'])->where('id', $id)->name('inventory.requests.update');
    Route::post('/requests/{id}/submit', [PurchaseRequestController::class, 'submit'])->where('id', $id)->name('inventory.requests.submit');
    Route::post('/requests/{id}/release', [PurchaseRequestController::class, 'release'])->where('id', $id)->name('inventory.requests.release');
    Route::post('/requests/{id}/cancel', [PurchaseRequestController::class, 'cancel'])->where('id', $id)->name('inventory.requests.cancel');
    Route::get('/orders', [PurchaseOrderController::class, 'index'])->name('inventory.orders');
    Route::get('/orders/{id}', [PurchaseOrderController::class, 'show'])->where('id', $id)->name('inventory.orders.show');
    Route::post('/orders', [PurchaseOrderController::class, 'store'])->name('inventory.orders.store');
    Route::post('/orders/{id}', [PurchaseOrderController::class, 'update'])->where('id', $id)->name('inventory.orders.update');
    Route::post('/orders/{id}/revise', [PurchaseOrderController::class, 'revise'])->where('id', $id)->name('inventory.orders.revise');
    Route::post('/orders/{id}/submit', [PurchaseOrderController::class, 'submit'])->where('id', $id)->name('inventory.orders.submit');
    Route::post('/orders/{id}/release', [PurchaseOrderController::class, 'release'])->where('id', $id)->name('inventory.orders.release');
    Route::post('/orders/{id}/issue', [PurchaseOrderController::class, 'issue'])->where('id', $id)->name('inventory.orders.issue');
    Route::post('/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->where('id', $id)->name('inventory.orders.cancel');
    Route::post('/orders/{id}/close', [PurchaseOrderController::class, 'close'])->where('id', $id)->name('inventory.orders.close');
    Route::get('/receipts', [GoodsReceiptController::class, 'index'])->name('inventory.receipts');
    Route::get('/receipts/{id}', [GoodsReceiptController::class, 'show'])->where('id', $id)->name('inventory.receipts.show');
    Route::post('/receipts', [GoodsReceiptController::class, 'store'])->middleware(['idempotent'])->name('inventory.receipts.store');
    Route::post('/receipts/{id}/lines/{line}/photos', [GoodsReceiptController::class, 'addPhoto'])->where('id', $id)->where('line', $id)->name('inventory.receipts.photos.store');
    Route::get('/receipts/{id}/photos/{photo}', [GoodsReceiptController::class, 'photo'])->where('id', $id)->where('photo', $id)->name('inventory.receipts.photos.show');
    Route::get('/invoices', [SupplierInvoiceController::class, 'index'])->name('inventory.invoices');
    Route::get('/invoices/{id}', [SupplierInvoiceController::class, 'show'])->where('id', $id)->name('inventory.invoices.show');
    Route::post('/invoices', [SupplierInvoiceController::class, 'store'])->middleware(['idempotent'])->name('inventory.invoices.store');
    Route::post('/invoices/{id}/approve', [SupplierInvoiceController::class, 'approve'])->where('id', $id)->name('inventory.invoices.approve');
    Route::post('/invoices/{id}/reject', [SupplierInvoiceController::class, 'reject'])->where('id', $id)->name('inventory.invoices.reject');
    Route::post('/invoices/{id}/documents', [SupplierInvoiceController::class, 'addDocument'])->where('id', $id)->name('inventory.invoices.documents.store');
    Route::get('/invoices/{id}/documents/{document}', [SupplierInvoiceController::class, 'document'])->where('id', $id)->where('document', $id)->name('inventory.invoices.documents.show');
    Route::get('/returns', [PurchaseReturnController::class, 'index'])->name('inventory.returns');
    Route::get('/returns/{id}', [PurchaseReturnController::class, 'show'])->where('id', $id)->name('inventory.returns.show');
    Route::post('/returns', [PurchaseReturnController::class, 'store'])->middleware(['idempotent'])->name('inventory.returns.store');
    Route::get('/reports/purchases', [PurchasingReportController::class, 'purchases'])->name('inventory.reports.purchases');
    Route::get('/reports/deliveries', [PurchasingReportController::class, 'deliveries'])->name('inventory.reports.deliveries');
    Route::get('/quotes', [PurchasingReportController::class, 'quotes'])->name('inventory.quotes');
    Route::post('/quotes', [PurchasingReportController::class, 'recordQuote'])->name('inventory.quotes.store');
    Route::get('/purchasing-settings', [PurchasingSettingsController::class, 'index'])->name('inventory.purchasing-settings');
    Route::post('/purchasing-settings', [PurchasingSettingsController::class, 'update'])->name('inventory.purchasing-settings.update');
    Route::post('/purchasing-settings/budgets', [PurchasingSettingsController::class, 'budget'])->name('inventory.purchasing-settings.budget');
    Route::get('/counts', [StockCountController::class, 'index'])->name('inventory.counts');
    Route::get('/counts/{id}', [StockCountController::class, 'show'])->where('id', $id)->name('inventory.counts.show');
    Route::post('/counts', [StockCountController::class, 'start'])->middleware(['idempotent'])->name('inventory.counts.start');
    Route::post('/counts/{id}/lines', [StockCountController::class, 'save'])->where('id', $id)->name('inventory.counts.save');
    Route::post('/counts/{id}/submit', [StockCountController::class, 'submit'])->where('id', $id)->name('inventory.counts.submit');
    Route::post('/counts/{id}/send-back', [StockCountController::class, 'sendBack'])->where('id', $id)->name('inventory.counts.send-back');
    Route::post('/counts/{id}/approve', [StockCountController::class, 'approve'])->where('id', $id)->name('inventory.counts.approve');
    Route::post('/counts/{id}/cancel', [StockCountController::class, 'cancel'])->where('id', $id)->name('inventory.counts.cancel');
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
    Route::get('/supplies', [SupplyUseController::class, 'index'])->name('laundry.supplies');
    Route::post('/supplies', [SupplyUseController::class, 'store'])->middleware(['idempotent'])->name('laundry.supplies.store');
    Route::get('/claims', [ClaimController::class, 'index'])->name('laundry.claims');
    Route::post('/claims', [ClaimController::class, 'store'])->name('laundry.claims.store');
    Route::post('/claims/cap', [ClaimController::class, 'cap'])->name('laundry.claims.cap');
    Route::post('/claims/{id}/approve', [ClaimController::class, 'approve'])->where('id', $id)->name('laundry.claims.approve');
    Route::post('/claims/{id}/reject', [ClaimController::class, 'reject'])->where('id', $id)->name('laundry.claims.reject');
    Route::get('/claims/{id}/photo', [ClaimController::class, 'photo'])->where('id', $id)->name('laundry.claims.photo');
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
    Route::get('/dashboard/today', [DashboardController::class, 'today'])->name('dashboard.today');
    Route::post('/dashboard/preferences', [DashboardController::class, 'savePreferences'])->name('dashboard.preferences');
    Route::delete('/dashboard/preferences', [DashboardController::class, 'resetPreferences'])->name('dashboard.preferences.reset');
    Route::prefix('reports')->group(function (): void {
        $id = '[0-9A-Za-z]{26}';

        Route::get('/', [ReportController::class, 'index'])->name('reports');
        Route::get('/movements', [ReportController::class, 'movements'])->name('reports.movements');
        Route::get('/movements/export', [ReportController::class, 'exportMovements'])->name('reports.movements.export');
        Route::get('/performance', [ReportController::class, 'performance'])->name('reports.performance');
        Route::get('/performance/export', [ReportController::class, 'exportPerformance'])->name('reports.performance.export');
        Route::get('/flash', [ReportController::class, 'flash'])->name('reports.flash');
        Route::get('/flash/export', [ReportController::class, 'exportFlash'])->name('reports.flash.export');
        Route::get('/housekeeping', [ReportController::class, 'housekeeping'])->name('reports.housekeeping');
        Route::get('/housekeeping/export', [ReportController::class, 'exportHousekeeping'])->name('reports.housekeeping.export');
        Route::get('/comparison', [ReportController::class, 'comparison'])->name('reports.comparison');
        Route::get('/comparison/export', [ReportController::class, 'exportComparison'])->name('reports.comparison.export');
        Route::get('/builder', [ReportBuilderController::class, 'index'])->name('reports.builder');
        Route::get('/builder/run', [ReportBuilderController::class, 'run'])->name('reports.builder.run');
        Route::get('/builder/export', [ReportBuilderController::class, 'export'])->name('reports.builder.export');
        Route::get('/schedules', [ReportScheduleController::class, 'index'])->name('reports.schedules');
        Route::post('/schedules', [ReportScheduleController::class, 'store'])->name('reports.schedules.store');
        Route::post('/schedules/{id}', [ReportScheduleController::class, 'update'])->where('id', $id)->name('reports.schedules.update');
        Route::post('/schedules/{id}/active', [ReportScheduleController::class, 'active'])->where('id', $id)->name('reports.schedules.active');
        Route::get('/exports', [ExportJobController::class, 'index'])->name('reports.exports');
        Route::post('/exports', [ExportJobController::class, 'store'])->middleware('throttle:bookings')->name('reports.exports.store');
        Route::post('/exports/seen', [ExportJobController::class, 'seen'])->name('reports.exports.seen');
        Route::get('/exports/{id}/download', [ExportJobController::class, 'download'])->where('id', $id)->name('reports.exports.download');
        Route::get('/outlets', [OutletController::class, 'index'])->name('reports.outlets');
        Route::post('/outlets', [OutletController::class, 'store'])->name('reports.outlets.store');
        Route::post('/outlets/{id}', [OutletController::class, 'rename'])->where('id', $id)->name('reports.outlets.rename');
        Route::post('/outlets/{id}/sources', [OutletController::class, 'addSource'])->where('id', $id)->name('reports.outlets.sources');
        Route::post('/outlets/{id}/sources/remove', [OutletController::class, 'removeSource'])->where('id', $id)->name('reports.outlets.sources.remove');
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

Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('fnb')->group(function (): void {
    $id = '[0-9a-z]{26}';

    Route::get('/pos', [BillController::class, 'floor'])->name('fnb.pos');
    // Mini bars of the rooms and room service (FR-FBS-020 to -025, -024).
    Route::get('/minibar', [MinibarController::class, 'index'])->name('fnb.minibar');
    Route::get('/minibar/room', [MinibarController::class, 'room'])->name('fnb.minibar.room');
    Route::post('/minibar/checks', [MinibarController::class, 'check'])->middleware(['idempotent'])->name('fnb.minibar.check');
    Route::post('/minibar/items', [MinibarController::class, 'createItem'])->name('fnb.minibar.items.create');
    Route::post('/minibar/items/{id}', [MinibarController::class, 'updateItem'])->where('id', $id)->name('fnb.minibar.items.update');
    Route::post('/minibar/items/{id}/active', [MinibarController::class, 'itemActive'])->where('id', $id)->name('fnb.minibar.items.active');
    Route::get('/room-service', [RoomServiceController::class, 'index'])->name('fnb.room-service');
    Route::post('/room-service', [RoomServiceController::class, 'place'])->middleware(['idempotent'])->name('fnb.room-service.place');
    Route::post('/room-service/{id}/status', [RoomServiceController::class, 'advance'])->where('id', $id)->name('fnb.room-service.advance');
    // Checklists and storage temperatures of the department (FR-KIT-008, FR-FBS-032).
    Route::get('/routines', [RoutineController::class, 'index'])->defaults('department', 'fnb')->name('fnb.routines');
    Route::get('/routines/templates', [RoutineController::class, 'templates'])->defaults('department', 'fnb')->name('fnb.routines.templates');
    Route::post('/routines/templates', [RoutineController::class, 'define'])->defaults('department', 'fnb')->name('fnb.routines.define');
    Route::get('/routines/performance', [RoutineController::class, 'performance'])->defaults('department', 'fnb')->name('fnb.routines.performance');
    Route::post('/routines/{template}/items/{item}/complete', [RoutineController::class, 'complete'])->where(['template' => $id, 'item' => 'i[0-9]{1,2}'])->defaults('department', 'fnb')->name('fnb.routines.complete');
    Route::get('/temperatures', [RoutineController::class, 'temperaturesPage'])->defaults('department', 'fnb')->name('fnb.temperatures');
    Route::post('/temperatures/points', [RoutineController::class, 'createPoint'])->defaults('department', 'fnb')->name('fnb.temperatures.points.create');
    Route::post('/temperatures/points/{id}', [RoutineController::class, 'updatePoint'])->where('id', $id)->defaults('department', 'fnb')->name('fnb.temperatures.points.update');
    Route::post('/temperatures/readings', [RoutineController::class, 'record'])->defaults('department', 'fnb')->name('fnb.temperatures.record');
    Route::get('/damage-reports', [FnbDamageReportController::class, 'index'])->name('fnb.damage');
    Route::post('/damage-reports', [FnbDamageReportController::class, 'store'])->name('fnb.damage.store');
    Route::get('/shift', [PaymentController::class, 'shift'])->name('fnb.shift');
    Route::post('/shift', [PaymentController::class, 'openShift'])->middleware(['idempotent'])->name('fnb.shift.open');
    Route::post('/shift/{id}/close', [PaymentController::class, 'closeShift'])->where('id', $id)->middleware(['idempotent'])->name('fnb.shift.close');
    Route::post('/bills/{id}/payments', [PaymentController::class, 'pay'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.pay');
    Route::post('/bills/{id}/payments/{payment}/qris', [PaymentController::class, 'qris'])->where('id', $id)->where('payment', $id)->middleware(['idempotent'])->name('fnb.bills.qris');
    Route::post('/bills', [BillController::class, 'open'])->middleware(['idempotent'])->name('fnb.bills.open');
    Route::get('/bills/{id}', [BillController::class, 'show'])->where('id', $id)->name('fnb.bills.show');
    Route::post('/bills/{id}/lines', [BillController::class, 'addLine'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.lines.store');
    Route::post('/bills/{id}/lines/{line}/remove', [BillController::class, 'removeLine'])->where('id', $id)->where('line', $id)->name('fnb.bills.lines.remove');
    Route::post('/bills/{id}/table', [BillController::class, 'moveTable'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.table');
    Route::post('/bills/{id}/merge', [BillController::class, 'merge'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.merge');
    Route::post('/bills/{id}/split', [BillController::class, 'split'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.split');
    Route::get('/register', [RegisterController::class, 'index'])->name('fnb.register');
    Route::get('/prices', [PriceRuleController::class, 'index'])->name('fnb.prices');
    Route::post('/prices', [PriceRuleController::class, 'store'])->name('fnb.prices.store');
    Route::post('/prices/{id}/retire', [PriceRuleController::class, 'retire'])->where('id', $id)->name('fnb.prices.retire');
    Route::post('/bills/{id}/send', [BillController::class, 'send'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.send');
    Route::post('/bills/{id}/lines/{line}/void-request', [BillController::class, 'requestVoid'])->where('id', $id)->where('line', $id)->middleware(['idempotent'])->name('fnb.bills.lines.void-request');
    Route::post('/bills/{id}/lines/{line}/void', [BillController::class, 'voidLine'])->where('id', $id)->where('line', $id)->middleware(['idempotent'])->name('fnb.bills.lines.void');
    Route::post('/bills/{id}/lines/{line}/discount-request', [BillController::class, 'requestDiscount'])->where('id', $id)->where('line', $id)->middleware(['idempotent'])->name('fnb.bills.lines.discount-request');
    Route::post('/bills/{id}/lines/{line}/discount', [BillController::class, 'discount'])->where('id', $id)->where('line', $id)->middleware(['idempotent'])->name('fnb.bills.lines.discount');
    Route::post('/bills/{id}/lines/{line}/discount/remove', [BillController::class, 'removeDiscount'])->where('id', $id)->where('line', $id)->middleware(['idempotent'])->name('fnb.bills.lines.discount.remove');
    Route::post('/bills/{id}/refund-request', [BillController::class, 'requestRefund'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.refund-request');
    Route::post('/bills/{id}/refund', [BillController::class, 'refund'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.refund');
    Route::post('/bills/{id}/reprint', [BillController::class, 'reprint'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.reprint');
    Route::post('/bills/{id}/cancel-request', [BillController::class, 'requestCancel'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.cancel-request');
    Route::post('/bills/{id}/cancel', [BillController::class, 'cancel'])->where('id', $id)->middleware(['idempotent'])->name('fnb.bills.cancel');
    Route::get('/outlets', [SetupController::class, 'outlets'])->name('fnb.outlets');
    Route::post('/outlets', [SetupController::class, 'storeOutlet'])->name('fnb.outlets.store');
    Route::post('/outlets/{id}', [SetupController::class, 'updateOutlet'])->where('id', $id)->name('fnb.outlets.update');
    Route::get('/outlets/{id}/tables', [SetupController::class, 'tables'])->where('id', $id)->name('fnb.tables');
    Route::post('/outlets/{id}/tables', [SetupController::class, 'storeTable'])->where('id', $id)->name('fnb.tables.store');
    Route::post('/tables/{id}', [SetupController::class, 'updateTable'])->where('id', $id)->name('fnb.tables.update');
    Route::get('/menu', [SetupController::class, 'menu'])->name('fnb.menu');
    Route::post('/outlets/{id}/categories', [SetupController::class, 'storeCategory'])->where('id', $id)->name('fnb.categories.store');
    Route::post('/categories/{id}', [SetupController::class, 'updateCategory'])->where('id', $id)->name('fnb.categories.update');
    Route::post('/items', [SetupController::class, 'storeItem'])->name('fnb.items.store');
    Route::post('/items/{id}', [SetupController::class, 'updateItem'])->where('id', $id)->name('fnb.items.update');
    Route::post('/items/{id}/availability', [SetupController::class, 'availability'])->where('id', $id)->name('fnb.items.availability');
    Route::post('/modifier-groups', [SetupController::class, 'storeGroup'])->name('fnb.groups.store');
    Route::post('/modifier-groups/{id}', [SetupController::class, 'updateGroup'])->where('id', $id)->name('fnb.groups.update');
});

Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('finance')->group(function (): void {
    $id = '[0-9a-hjkmnp-tv-z]{26}';

    Route::get('/', fn () => redirect()->route('finance.payables'));
    Route::get('/payables', [PayableController::class, 'index'])->name('finance.payables');
    Route::get('/payables/{id}', [PayableController::class, 'show'])->where('id', $id)->name('finance.payables.show');
    Route::post('/payables/{id}/classify', [PayableController::class, 'classify'])->where('id', $id)->name('finance.payables.classify');
    Route::post('/payables/{id}/credits', [PayableController::class, 'applyCredit'])->where('id', $id)->name('finance.payables.credits');
    Route::get('/aging', [PayableController::class, 'aging'])->name('finance.aging');
    Route::get('/schedule', [PayableController::class, 'schedule'])->name('finance.schedule');
    Route::get('/payments', [SupplierPaymentController::class, 'index'])->name('finance.payments');
    Route::get('/payments/{id}', [SupplierPaymentController::class, 'show'])->where('id', $id)->name('finance.payments.show');
    Route::post('/payments', [SupplierPaymentController::class, 'store'])->middleware(['idempotent'])->name('finance.payments.store');
    Route::post('/payments/{id}/release', [SupplierPaymentController::class, 'release'])->where('id', $id)->name('finance.payments.release');
    Route::post('/payments/{id}/cancel', [SupplierPaymentController::class, 'cancel'])->where('id', $id)->name('finance.payments.cancel');
    Route::post('/payments/{id}/reverse', [SupplierPaymentController::class, 'reverse'])->where('id', $id)->name('finance.payments.reverse');
    Route::post('/payments/{id}/proofs', [SupplierPaymentController::class, 'addProof'])->where('id', $id)->name('finance.payments.proofs.store');
    Route::get('/payments/{id}/proofs/{proof}', [SupplierPaymentController::class, 'proof'])->where('id', $id)->where('proof', $id)->name('finance.payments.proofs.show');
    Route::get('/accounts', [ExpenseAccountController::class, 'index'])->name('finance.accounts');
    Route::post('/accounts', [ExpenseAccountController::class, 'store'])->name('finance.accounts.store');
    Route::post('/accounts/{id}', [ExpenseAccountController::class, 'update'])->where('id', $id)->name('finance.accounts.update');
    Route::get('/revenue', [RevenueController::class, 'index'])->name('finance.revenue');
    Route::get('/revenue/{date}', [RevenueController::class, 'show'])->where('date', '\d{4}-\d{2}-\d{2}')->name('finance.revenue.show');
    Route::post('/revenue/{date}/verify', [RevenueController::class, 'verify'])->where('date', '\d{4}-\d{2}-\d{2}')->name('finance.revenue.verify');
    Route::get('/cash', [CashReconciliationController::class, 'index'])->name('finance.cash');
    Route::post('/cash/shifts/{id}/receive', [CashReconciliationController::class, 'receive'])->where('id', $id)->middleware(['idempotent'])->name('finance.cash.receive');
    Route::post('/cash/exceptions/{id}/settle', [CashReconciliationController::class, 'settle'])->where('id', $id)->name('finance.cash.settle');
    Route::get('/receivables', [ReceivableController::class, 'index'])->name('finance.receivables');
    Route::post('/receivables', [ReceivableController::class, 'store'])->middleware(['idempotent'])->name('finance.receivables.store');
    Route::get('/receivables/aging', [ReceivableController::class, 'aging'])->name('finance.receivables.aging');
    Route::get('/receivables/{id}', [ReceivableController::class, 'show'])->where('id', $id)->name('finance.receivables.show');
    Route::post('/receivables/{id}/receipts', [ReceivableController::class, 'receive'])->where('id', $id)->middleware(['idempotent'])->name('finance.receivables.receive');
    Route::post('/receivables/{id}/notes', [ReceivableController::class, 'note'])->where('id', $id)->name('finance.receivables.note');
    Route::post('/receivables/{id}/adjustments', [ReceivableController::class, 'adjust'])->where('id', $id)->name('finance.receivables.adjust');
    Route::post('/receivables/receipts/{id}/reverse', [ReceivableController::class, 'reverseReceipt'])->where('id', $id)->name('finance.receivables.receipts.reverse');
    Route::get('/customers', [ReceivableController::class, 'customers'])->name('finance.customers');
    Route::post('/customers', [ReceivableController::class, 'storeCustomer'])->name('finance.customers.store');
    Route::post('/customers/{id}', [ReceivableController::class, 'updateCustomer'])->where('id', $id)->name('finance.customers.update');
    Route::get('/payroll', [PayrollDisbursementController::class, 'index'])->name('finance.payroll');
    Route::post('/payroll/{id}/verify', [PayrollDisbursementController::class, 'verify'])->where('id', $id)->name('finance.payroll.verify');
    Route::post('/payroll/{id}/pay', [PayrollDisbursementController::class, 'pay'])->where('id', $id)->name('finance.payroll.pay');
    Route::get('/tax', [TaxController::class, 'index'])->name('finance.tax');
    Route::post('/tax/settings', [TaxController::class, 'settings'])->name('finance.tax.settings');
    Route::post('/tax/{period}/report', [TaxController::class, 'report'])->where('period', '\d{4}-\d{2}')->name('finance.tax.report');
    Route::post('/tax/{period}/deposit', [TaxController::class, 'deposit'])->where('period', '\d{4}-\d{2}')->name('finance.tax.deposit');
    Route::get('/tax/{period}/recap', [TaxController::class, 'recap'])->where('period', '\d{4}-\d{2}')->name('finance.tax.recap');
    Route::get('/petty', [PettyCashController::class, 'index'])->name('finance.petty');
    Route::post('/petty', [PettyCashController::class, 'store'])->name('finance.petty.store');
    Route::get('/petty/settlements/{id}', [PettyCashController::class, 'showSettlement'])->where('id', $id)->name('finance.petty.settlements.show');
    Route::post('/petty/settlements/{id}/decide', [PettyCashController::class, 'decide'])->where('id', $id)->name('finance.petty.settlements.decide');
    Route::get('/petty/{id}', [PettyCashController::class, 'show'])->where('id', $id)->name('finance.petty.show');
    Route::post('/petty/{id}', [PettyCashController::class, 'update'])->where('id', $id)->name('finance.petty.update');
    Route::post('/petty/{id}/vouchers', [PettyCashController::class, 'record'])->where('id', $id)->middleware(['idempotent'])->name('finance.petty.vouchers.store');
    Route::post('/petty/{id}/settlements', [PettyCashController::class, 'submit'])->where('id', $id)->middleware(['idempotent'])->name('finance.petty.settlements.store');
    Route::post('/petty/vouchers/{id}/proofs', [PettyCashController::class, 'addProof'])->where('id', $id)->name('finance.petty.proofs.store');
    Route::get('/petty/vouchers/{id}/proofs/{proof}', [PettyCashController::class, 'proof'])->where('id', $id)->where('proof', $id)->name('finance.petty.proofs.show');
    Route::post('/petty/vouchers/{id}/void', [PettyCashController::class, 'void'])->where('id', $id)->name('finance.petty.vouchers.void');
    Route::get('/pnl', [ManagementReportController::class, 'pnl'])->name('finance.pnl');
    Route::post('/pnl/mappings', [ManagementReportController::class, 'mapOutlet'])->name('finance.pnl.mappings');
    Route::get('/cashflow', [ManagementReportController::class, 'cashFlow'])->name('finance.cashflow');
    Route::post('/cashflow/opening', [ManagementReportController::class, 'setOpening'])->name('finance.cashflow.opening');
    Route::get('/stock-value', [ManagementReportController::class, 'stockValue'])->name('finance.stock-value');
    Route::get('/food-cost', [ManagementReportController::class, 'foodCost'])->name('finance.food-cost');
    Route::post('/food-cost/target', [ManagementReportController::class, 'setFoodCostTarget'])->name('finance.food-cost.target');
    Route::get('/export', [ManagementReportController::class, 'exportPage'])->name('finance.export');
    Route::get('/export/{dataset}', [ManagementReportController::class, 'export'])->where('dataset', '[a-z_]{3,24}')->name('finance.export.download');
    Route::get('/recurring', [RecurringExpenseController::class, 'index'])->name('finance.recurring');
    Route::post('/recurring', [RecurringExpenseController::class, 'store'])->name('finance.recurring.store');
    Route::get('/recurring/{id}', [RecurringExpenseController::class, 'show'])->where('id', $id)->name('finance.recurring.show');
    Route::post('/recurring/{id}', [RecurringExpenseController::class, 'update'])->where('id', $id)->name('finance.recurring.update');
    Route::post('/recurring/{id}/settle', [RecurringExpenseController::class, 'settle'])->where('id', $id)->middleware(['idempotent'])->name('finance.recurring.settle');
    Route::get('/budget', [BudgetController::class, 'index'])->name('finance.budget');
    Route::post('/budget', [BudgetController::class, 'save'])->name('finance.budget.save');
    Route::get('/budget/report', [BudgetController::class, 'report'])->name('finance.budget.report');
    Route::get('/corrections', [CorrectionController::class, 'index'])->name('finance.corrections');
    Route::post('/corrections', [CorrectionController::class, 'store'])->middleware(['idempotent'])->name('finance.corrections.store');
    Route::get('/corrections/{id}', [CorrectionController::class, 'show'])->where('id', $id)->name('finance.corrections.show');
    Route::post('/corrections/{id}/decide', [CorrectionController::class, 'decide'])->where('id', $id)->name('finance.corrections.decide');
    Route::get('/settlements', [SettlementController::class, 'index'])->name('finance.settlements');
    Route::post('/settlements', [SettlementController::class, 'store'])->name('finance.settlements.store');
    Route::get('/exceptions', [ExceptionController::class, 'index'])->name('finance.exceptions');
    Route::post('/exceptions', [ExceptionController::class, 'store'])->middleware(['idempotent'])->name('finance.exceptions.store');
    Route::post('/exceptions/{id}/reconcile', [ExceptionController::class, 'reconcile'])->where('id', $id)->name('finance.exceptions.reconcile');
    Route::get('/audit', [FinanceAuditController::class, 'index'])->name('finance.audit');
});

Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('guest')->group(function (): void {
    $id = '[0-9a-z]{26}';

    Route::get('/qr', [QrPointController::class, 'index'])->name('guest.qr');
    Route::post('/qr', [QrPointController::class, 'provision'])->name('guest.qr.provision');
    Route::get('/qr/print', [QrPointController::class, 'print'])->name('guest.qr.print');
    Route::post('/qr/{id}/rotate', [QrPointController::class, 'rotate'])->where('id', $id)->name('guest.qr.rotate');
    Route::post('/qr/{id}/active', [QrPointController::class, 'active'])->where('id', $id)->name('guest.qr.active');
    Route::get('/orders', [GuestOrderQueueController::class, 'index'])->name('guest.orders.queue');
    Route::post('/orders/{id}/decide', [GuestOrderQueueController::class, 'decide'])->where('id', $id)->name('guest.orders.decide');
});

Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('kitchen')->group(function (): void {
    $id = '[0-9a-z]{26}';

    Route::get('/', [KitchenBoardController::class, 'show'])->name('kitchen.board');
    Route::get('/tickets', [KitchenBoardController::class, 'tickets'])->name('kitchen.tickets');
    Route::post('/tickets/{id}/advance', [KitchenBoardController::class, 'advance'])->where('id', $id)->name('kitchen.tickets.advance');
    Route::post('/items/{id}/availability', [KitchenBoardController::class, 'availability'])->where('id', $id)->name('kitchen.items.availability');
    Route::post('/settings', [KitchenBoardController::class, 'saveSettings'])->name('kitchen.settings.save');
    // Checklists and storage temperatures of the department (FR-KIT-008, FR-FBS-032).
    Route::get('/routines', [RoutineController::class, 'index'])->defaults('department', 'kitchen')->name('kitchen.routines');
    Route::get('/routines/templates', [RoutineController::class, 'templates'])->defaults('department', 'kitchen')->name('kitchen.routines.templates');
    Route::post('/routines/templates', [RoutineController::class, 'define'])->defaults('department', 'kitchen')->name('kitchen.routines.define');
    Route::get('/routines/performance', [RoutineController::class, 'performance'])->defaults('department', 'kitchen')->name('kitchen.routines.performance');
    Route::post('/routines/{template}/items/{item}/complete', [RoutineController::class, 'complete'])->where(['template' => $id, 'item' => 'i[0-9]{1,2}'])->defaults('department', 'kitchen')->name('kitchen.routines.complete');
    Route::get('/temperatures', [RoutineController::class, 'temperaturesPage'])->defaults('department', 'kitchen')->name('kitchen.temperatures');
    Route::post('/temperatures/points', [RoutineController::class, 'createPoint'])->defaults('department', 'kitchen')->name('kitchen.temperatures.points.create');
    Route::post('/temperatures/points/{id}', [RoutineController::class, 'updatePoint'])->where('id', $id)->defaults('department', 'kitchen')->name('kitchen.temperatures.points.update');
    Route::post('/temperatures/readings', [RoutineController::class, 'record'])->defaults('department', 'kitchen')->name('kitchen.temperatures.record');
    Route::get('/damage-reports', [KitchenDamageReportController::class, 'index'])->name('kitchen.damage');
    Route::post('/damage-reports', [KitchenDamageReportController::class, 'store'])->name('kitchen.damage.store');
    Route::get('/waste', [WasteController::class, 'index'])->name('kitchen.waste');
    Route::post('/waste', [WasteController::class, 'record'])->middleware(['idempotent'])->name('kitchen.waste.record');
    Route::get('/production', [ProductionController::class, 'index'])->name('kitchen.production');
    Route::post('/production/formulas', [ProductionController::class, 'define'])->name('kitchen.production.formulas.define');
    Route::post('/production/formulas/{id}/retire', [ProductionController::class, 'retire'])->where('id', $id)->name('kitchen.production.formulas.retire');
    Route::post('/production', [ProductionController::class, 'record'])->middleware(['idempotent'])->name('kitchen.production.record');
    Route::get('/menu-report', [MenuReportController::class, 'index'])->name('kitchen.menu-report');
    Route::get('/recipes', [RecipeController::class, 'index'])->name('kitchen.recipes');
    Route::get('/recipes/{id}', [RecipeController::class, 'show'])->where('id', $id)->name('kitchen.recipes.show');
    Route::post('/recipes/{id}', [RecipeController::class, 'save'])->where('id', $id)->name('kitchen.recipes.save');
});

Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('hr')->group(function (): void {
    $id = '[0-9a-z]{26}';

    Route::get('/employees', [EmployeeController::class, 'index'])->name('hr.employees');
    Route::post('/employees', [EmployeeController::class, 'create'])->middleware(['idempotent'])->name('hr.employees.create');
    Route::post('/settings', [EmployeeController::class, 'settings'])->name('hr.settings');
    Route::get('/employees/{id}', [EmployeeController::class, 'show'])->where('id', $id)->name('hr.employees.show');
    Route::post('/employees/{id}', [EmployeeController::class, 'update'])->where('id', $id)->name('hr.employees.update');
    Route::post('/employees/{id}/offboard', [EmployeeController::class, 'offboard'])->where('id', $id)->name('hr.employees.offboard');
    Route::get('/employees/{id}/documents', [EmployeeController::class, 'documents'])->where('id', $id)->name('hr.documents');
    Route::post('/employees/{id}/documents', [EmployeeController::class, 'addDocument'])->where('id', $id)->name('hr.documents.add');
    Route::get('/roster', [RosterController::class, 'index'])->name('hr.roster');
    Route::post('/roster/assign', [RosterController::class, 'assign'])->name('hr.roster.assign');
    Route::post('/roster/copy', [RosterController::class, 'copy'])->name('hr.roster.copy');
    Route::post('/roster/minimums', [RosterController::class, 'minimums'])->name('hr.roster.minimums');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('hr.attendance');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])->middleware(['idempotent'])->name('hr.attendance.in');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])->middleware(['idempotent'])->name('hr.attendance.out');
    Route::post('/attendance/manual', [AttendanceController::class, 'manual'])->middleware(['idempotent'])->name('hr.attendance.manual');
    Route::post('/attendance/settings', [AttendanceController::class, 'settings'])->name('hr.attendance.settings');
    Route::post('/overtime', [AttendanceAdjustmentController::class, 'requestOvertime'])->middleware(['idempotent'])->name('hr.overtime.request');
    Route::post('/overtime/{id}/release', [AttendanceAdjustmentController::class, 'releaseOvertime'])->where('id', $id)->name('hr.overtime.release');
    Route::post('/overtime/{id}/cancel', [AttendanceAdjustmentController::class, 'cancelOvertime'])->where('id', $id)->name('hr.overtime.cancel');
    Route::post('/attendance/corrections', [AttendanceAdjustmentController::class, 'requestCorrection'])->middleware(['idempotent'])->name('hr.attendance.corrections.request');
    Route::post('/attendance/corrections/{id}/apply', [AttendanceAdjustmentController::class, 'applyCorrection'])->where('id', $id)->name('hr.attendance.corrections.apply');
    Route::post('/attendance/corrections/{id}/cancel', [AttendanceAdjustmentController::class, 'cancelCorrection'])->where('id', $id)->name('hr.attendance.corrections.cancel');
    Route::get('/attendance/{id}/photo/{which}', [AttendanceController::class, 'photo'])->where('id', $id)->where('which', 'in|out')->name('hr.attendance.photo');
    Route::get('/performance', [PerformanceController::class, 'index'])->name('hr.performance');
    Route::get('/payroll', [PayrollController::class, 'index'])->name('hr.payroll');
    Route::get('/payroll/runs', [PayrollRunController::class, 'index'])->name('hr.payroll.runs');
    Route::post('/payroll/runs', [PayrollRunController::class, 'create'])->name('hr.payroll.runs.create');
    Route::post('/payroll/runs/{id}/calculate', [PayrollRunController::class, 'calculate'])->where('id', $id)->name('hr.payroll.runs.calculate');
    Route::post('/payroll/runs/{id}/review', [PayrollRunController::class, 'review'])->where('id', $id)->name('hr.payroll.runs.review');
    Route::post('/payroll/runs/{id}/approve', [PayrollRunController::class, 'approve'])->where('id', $id)->name('hr.payroll.runs.approve');
    Route::post('/payroll/runs/{id}/reopen', [PayrollRunController::class, 'reopen'])->where('id', $id)->name('hr.payroll.runs.reopen');
    Route::post('/payroll/runs/{id}/discard', [PayrollRunController::class, 'discard'])->where('id', $id)->name('hr.payroll.runs.discard');
    Route::post('/payroll/adjustments', [PayrollRunController::class, 'adjust'])->name('hr.payroll.adjustments.create');
    Route::post('/payroll/adjustments/{id}/cancel', [PayrollRunController::class, 'cancelAdjustment'])->where('id', $id)->name('hr.payroll.adjustments.cancel');
    Route::post('/payroll/runs/{id}/lock', [PayrollRunController::class, 'lock'])->where('id', $id)->name('hr.payroll.runs.lock');
    Route::get('/payroll/runs/{id}/export', [PayslipController::class, 'export'])->where('id', $id)->name('hr.payroll.runs.export');
    Route::get('/payroll/runs/{id}/payslips/{employee}', [PayslipController::class, 'of'])->where('id', $id)->where('employee', $id)->name('hr.payroll.payslip');
    Route::get('/payslips', [PayslipController::class, 'mine'])->name('hr.payslips');
    Route::get('/payslips/{id}', [PayslipController::class, 'own'])->where('id', $id)->name('hr.payslips.show');
    Route::get('/service-charge', [ServiceChargeController::class, 'index'])->name('hr.service-charge');
    Route::post('/service-charge', [ServiceChargeController::class, 'create'])->name('hr.service-charge.create');
    Route::post('/service-charge/settings', [ServiceChargeController::class, 'saveSettings'])->name('hr.service-charge.settings');
    Route::post('/service-charge/{id}/calculate', [ServiceChargeController::class, 'calculate'])->where('id', $id)->name('hr.service-charge.calculate');
    Route::post('/service-charge/{id}/approve', [ServiceChargeController::class, 'approve'])->where('id', $id)->name('hr.service-charge.approve');
    Route::post('/service-charge/{id}/discard', [ServiceChargeController::class, 'discard'])->where('id', $id)->name('hr.service-charge.discard');
    Route::get('/me', [EmployeePortalController::class, 'index'])->name('hr.me');
    Route::get('/swaps', [ShiftSwapController::class, 'index'])->name('hr.swaps');
    Route::post('/swaps', [ShiftSwapController::class, 'request'])->middleware(['idempotent'])->name('hr.swaps.request');
    Route::post('/swaps/{id}/accept', [ShiftSwapController::class, 'accept'])->where('id', $id)->name('hr.swaps.accept');
    Route::post('/swaps/{id}/decline', [ShiftSwapController::class, 'decline'])->where('id', $id)->name('hr.swaps.decline');
    Route::post('/swaps/{id}/approve', [ShiftSwapController::class, 'approve'])->where('id', $id)->name('hr.swaps.approve');
    Route::post('/swaps/{id}/reject', [ShiftSwapController::class, 'reject'])->where('id', $id)->name('hr.swaps.reject');
    Route::post('/swaps/{id}/cancel', [ShiftSwapController::class, 'cancel'])->where('id', $id)->name('hr.swaps.cancel');
    Route::get('/conduct', [ConductController::class, 'index'])->name('hr.conduct');
    Route::post('/conduct', [ConductController::class, 'create'])->middleware(['idempotent'])->name('hr.conduct.create');
    Route::post('/conduct/{id}/revoke', [ConductController::class, 'revoke'])->where('id', $id)->name('hr.conduct.revoke');
    Route::get('/conduct/{id}/letter', [ConductController::class, 'letter'])->where('id', $id)->name('hr.conduct.letter');
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('hr.announcements');
    Route::post('/announcements', [AnnouncementController::class, 'publish'])->middleware(['idempotent'])->name('hr.announcements.publish');
    Route::post('/announcements/{id}/withdraw', [AnnouncementController::class, 'withdraw'])->where('id', $id)->name('hr.announcements.withdraw');
    Route::post('/announcements/{id}/read', [AnnouncementController::class, 'read'])->where('id', $id)->name('hr.announcements.read');
    Route::post('/announcements/{id}/acknowledge', [AnnouncementController::class, 'acknowledge'])->where('id', $id)->name('hr.announcements.acknowledge');
    Route::get('/announcements/{id}/document', [AnnouncementController::class, 'document'])->where('id', $id)->name('hr.announcements.document');
    Route::get('/appraisals', [AppraisalController::class, 'index'])->name('hr.appraisals');
    Route::post('/appraisals', [AppraisalController::class, 'create'])->middleware(['idempotent'])->name('hr.appraisals.create');
    Route::post('/appraisals/forms', [AppraisalController::class, 'createForm'])->name('hr.appraisals.forms.create');
    Route::post('/appraisals/forms/baseline', [AppraisalController::class, 'baselineForm'])->name('hr.appraisals.forms.baseline');
    Route::post('/appraisals/forms/{id}/active', [AppraisalController::class, 'formActive'])->where('id', $id)->name('hr.appraisals.forms.active');
    Route::post('/appraisals/{id}', [AppraisalController::class, 'save'])->where('id', $id)->name('hr.appraisals.save');
    Route::post('/appraisals/{id}/sign', [AppraisalController::class, 'sign'])->where('id', $id)->middleware('password.confirm')->name('hr.appraisals.sign');
    Route::post('/appraisals/{id}/sign-employee', [AppraisalController::class, 'signAsEmployee'])->where('id', $id)->middleware('password.confirm')->name('hr.appraisals.sign-employee');
    Route::post('/appraisals/{id}/cancel', [AppraisalController::class, 'cancel'])->where('id', $id)->name('hr.appraisals.cancel');
    Route::post('/payroll/pay', [PayrollController::class, 'setPay'])->middleware(['idempotent'])->name('hr.payroll.pay');
    Route::post('/payroll/profile', [PayrollController::class, 'saveProfile'])->name('hr.payroll.profile');
    Route::post('/payroll/settings', [PayrollController::class, 'saveSettings'])->name('hr.payroll.settings');
    Route::post('/payroll/components', [PayrollController::class, 'createComponent'])->name('hr.payroll.components.create');
    Route::post('/payroll/components/baseline', [PayrollController::class, 'baseline'])->name('hr.payroll.components.baseline');
    Route::post('/payroll/components/{id}', [PayrollController::class, 'updateComponent'])->where('id', $id)->name('hr.payroll.components.update');
    Route::post('/payroll/components/{id}/active', [PayrollController::class, 'componentActive'])->where('id', $id)->name('hr.payroll.components.active');
    Route::get('/leave', [LeaveController::class, 'index'])->name('hr.leave');
    Route::post('/leave', [LeaveController::class, 'request'])->middleware(['idempotent'])->name('hr.leave.request');
    Route::post('/leave/adjust', [LeaveController::class, 'adjust'])->middleware(['idempotent'])->name('hr.leave.adjust');
    Route::post('/leave/types', [LeaveController::class, 'createType'])->name('hr.leave.types.create');
    Route::post('/leave/types/baseline', [LeaveController::class, 'baseline'])->name('hr.leave.types.baseline');
    Route::post('/leave/types/{id}', [LeaveController::class, 'updateType'])->where('id', $id)->name('hr.leave.types.update');
    Route::post('/leave/types/{id}/active', [LeaveController::class, 'typeActive'])->where('id', $id)->name('hr.leave.types.active');
    Route::post('/leave/{id}/release', [LeaveController::class, 'release'])->where('id', $id)->name('hr.leave.release');
    Route::post('/leave/{id}/cancel', [LeaveController::class, 'cancel'])->where('id', $id)->name('hr.leave.cancel');
    Route::get('/leave/{id}/evidence', [LeaveController::class, 'evidence'])->where('id', $id)->name('hr.leave.evidence');
    Route::get('/shift-patterns', [RosterController::class, 'patterns'])->name('hr.patterns');
    Route::post('/shift-patterns', [RosterController::class, 'createPattern'])->name('hr.patterns.create');
    Route::post('/shift-patterns/baseline', [RosterController::class, 'baseline'])->name('hr.patterns.baseline');
    Route::post('/shift-patterns/{id}', [RosterController::class, 'updatePattern'])->where('id', $id)->name('hr.patterns.update');
    Route::post('/shift-patterns/{id}/active', [RosterController::class, 'patternActive'])->where('id', $id)->name('hr.patterns.active');
    Route::get('/documents/{document}/file', [EmployeeController::class, 'download'])->where('document', $id)->name('hr.documents.file');
});

Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'property'])->prefix('maintenance')->group(function (): void {
    $id = '[0-9a-z]{26}';

    Route::get('/', [WorkOrderController::class, 'index'])->name('maintenance.work-orders');
    Route::post('/work-orders', [WorkOrderController::class, 'report'])->name('maintenance.work-orders.report');
    Route::get('/work-orders/{id}', [WorkOrderController::class, 'show'])->where('id', $id)->name('maintenance.work-orders.show');
    Route::get('/work-orders/{id}/photo/{which}', [WorkOrderController::class, 'photo'])->where('id', $id)->where('which', 'report|done')->name('maintenance.work-orders.photo');
    Route::post('/work-orders/{id}/assign', [WorkOrderController::class, 'assign'])->where('id', $id)->name('maintenance.work-orders.assign');
    Route::post('/work-orders/{id}/priority', [WorkOrderController::class, 'priority'])->where('id', $id)->name('maintenance.work-orders.priority');
    Route::post('/work-orders/{id}/start', [WorkOrderController::class, 'start'])->where('id', $id)->name('maintenance.work-orders.start');
    Route::post('/work-orders/{id}/hold', [WorkOrderController::class, 'hold'])->where('id', $id)->name('maintenance.work-orders.hold');
    Route::post('/work-orders/{id}/resume', [WorkOrderController::class, 'resume'])->where('id', $id)->name('maintenance.work-orders.resume');
    Route::post('/work-orders/{id}/complete', [WorkOrderController::class, 'complete'])->where('id', $id)->name('maintenance.work-orders.complete');
    Route::post('/work-orders/{id}/cancel', [WorkOrderController::class, 'cancel'])->where('id', $id)->name('maintenance.work-orders.cancel');
    Route::post('/work-orders/{id}/block', [WorkOrderController::class, 'block'])->where('id', $id)->name('maintenance.work-orders.block');
    Route::get('/work-orders/{id}/parts', [PartsController::class, 'show'])->where('id', $id)->name('maintenance.parts.show');
    Route::post('/work-orders/{id}/parts', [PartsController::class, 'use'])->where('id', $id)->middleware(['idempotent'])->name('maintenance.parts.use');
    Route::post('/work-orders/{id}/part-requests', [PartsController::class, 'request'])->where('id', $id)->middleware(['idempotent'])->name('maintenance.parts.request');
    Route::post('/work-orders/{id}/release-room', [WorkOrderController::class, 'releaseRoom'])->where('id', $id)->name('maintenance.work-orders.release-room');
    Route::post('/sla', [WorkOrderController::class, 'sla'])->name('maintenance.sla');
    Route::get('/assets', [AssetController::class, 'index'])->name('maintenance.assets');
    Route::post('/assets', [AssetController::class, 'store'])->name('maintenance.assets.store');
    Route::get('/assets/{id}', [AssetController::class, 'show'])->where('id', $id)->name('maintenance.assets.show');
    Route::post('/assets/{id}/retire', [AssetController::class, 'retire'])->where('id', $id)->name('maintenance.assets.retire');
    Route::post('/assets/{id}/readings', [AssetController::class, 'reading'])->where('id', $id)->name('maintenance.assets.readings');
    Route::post('/assets/{id}/plans', [AssetController::class, 'addPlan'])->where('id', $id)->name('maintenance.assets.plans');
    Route::post('/plans/{plan}/active', [AssetController::class, 'planActive'])->where('plan', $id)->name('maintenance.plans.active');
    Route::get('/duties', [DutyController::class, 'index'])->name('maintenance.duties');
    Route::post('/duties', [DutyController::class, 'create'])->middleware(['idempotent'])->name('maintenance.duties.create');
    Route::post('/duties/{id}', [DutyController::class, 'update'])->where('id', $id)->name('maintenance.duties.update');
    Route::post('/duties/{id}/active', [DutyController::class, 'active'])->where('id', $id)->name('maintenance.duties.active');
    Route::get('/duty-runs/{id}', [DutyController::class, 'show'])->where('id', $id)->name('maintenance.duty-runs.show');
    Route::post('/duty-runs/{id}/steps/{step}', [DutyController::class, 'check'])->where('id', $id)->where('step', $id)->name('maintenance.duty-runs.check');
    Route::post('/duty-runs/{id}/complete', [DutyController::class, 'complete'])->where('id', $id)->name('maintenance.duty-runs.complete');
    Route::get('/vendor-work', [VendorJobController::class, 'index'])->name('maintenance.vendor');
    Route::post('/vendor-jobs', [VendorJobController::class, 'create'])->middleware(['idempotent'])->name('maintenance.vendor.create');
    Route::get('/vendor-jobs/{id}', [VendorJobController::class, 'show'])->where('id', $id)->name('maintenance.vendor.show');
    Route::get('/vendor-jobs/{id}/proof', [VendorJobController::class, 'proof'])->where('id', $id)->name('maintenance.vendor.proof');
    Route::post('/vendor-jobs/{id}/quotes', [VendorJobController::class, 'quote'])->where('id', $id)->name('maintenance.vendor.quote');
    Route::post('/vendor-jobs/{id}/choose', [VendorJobController::class, 'choose'])->where('id', $id)->name('maintenance.vendor.choose');
    Route::post('/vendor-jobs/{id}/release', [VendorJobController::class, 'release'])->where('id', $id)->name('maintenance.vendor.release');
    Route::post('/vendor-jobs/{id}/schedule', [VendorJobController::class, 'schedule'])->where('id', $id)->name('maintenance.vendor.schedule');
    Route::post('/vendor-jobs/{id}/complete', [VendorJobController::class, 'complete'])->where('id', $id)->name('maintenance.vendor.complete');
    Route::post('/vendor-jobs/{id}/cancel', [VendorJobController::class, 'cancel'])->where('id', $id)->name('maintenance.vendor.cancel');
    Route::get('/reports', [MaintenanceReportController::class, 'index'])->name('maintenance.reports');
    Route::post('/escalations/{id}/acknowledge', [MaintenanceReportController::class, 'acknowledge'])->where('id', $id)->name('maintenance.escalations.acknowledge');
});
