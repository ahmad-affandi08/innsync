<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Feedback\FeedbackService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Folios\LateChargeService;
use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Modules\FrontOffice\Application\Reservations\RateChangeService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Routine\ShiftLogService;
use App\Modules\FrontOffice\Application\Routine\SopService;
use App\Modules\FrontOffice\Application\Stays\GuestCorrectionService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Application\Stays\StayTimeFeeService;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\Housekeeping\Application\ChecklistService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Application\LinenService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\ObligationService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\Context;

/**
 * A small hotel for tests: one property with three Deluxe rooms, a 10 percent service charge and 10 percent tax, a plan
 * priced Rp 1.000.000 a night, the business date set to 2026-10-01 and users for the common roles. Needs `SignsInToProperty`.
 */
trait BuildsHotel
{
    private const PROPERTY = '01arz3ndektsv4rrffq69g5fav';

    private AdjustableClock $clock;

    private string $adminId;

    private string $managerId;

    private string $viewerId;

    private string $supervisorId;

    /** May view stays and the identity details of guests. */
    private string $auditorId;

    /** Housekeeping: supervisor (manage, inspect, view, settings), two attendants, and a chief who may inspect and waive. */
    private string $hkSupervisorId;

    private string $attendantId;

    private string $attendant2Id;

    private string $hkChiefId;

    /** Laundry: a clerk (hand over and deliver for housekeeping), a launderer (count and process), a manager (prices, cancel, view). */
    private string $clerkId;

    private string $laundererId;

    private string $laundryManagerId;

    /** Linen (FR-HK-009 to -011): two housekeeping linen handlers (one sends, the other counts) and a laundry linen handler. */
    private string $linenManagerId;

    private string $linenManager2Id;

    private string $linenLaundryId;

    /** Housekeeping checklists (FR-HK-005): a manager of the templates, two people who tick items and one who may only look at the figures. */
    private string $hkListManagerId;

    private string $hkListStaffId;

    private string $hkListStaff2Id;

    private string $hkListViewerId;

    /** Tax and service charge obligations (FR-DSH-013, -014): someone who may mark a month as reported and change the settings. */
    private string $financeId;

    /** Early check-in and late check-out fees (FR-FO-037): someone who writes the policies, someone who charges the fee, someone who may waive it. */
    private string $feePolicyId;

    private string $feeClerkId;

    private string $feeWaiverId;

    /** Reporting: an analyst (dashboard with revenue, reports, audit; identity masked), a registrar (guest reports and export, identity in clear), and a dashboard-only viewer. */
    private string $analystId;

    private string $registrarId;

    private string $dashOnlyId;

    /** Cashier shifts: two cashiers who can also take payments and refunds, and a cashier supervisor (review, close for others, the require-a-shift switch). */
    private string $cashierId;

    private string $cashier2Id;

    private string $cashSupervisorId;

    /** Room price changes (FR-FO-013): someone who may change the price of a booked reservation. */
    private string $rateManagerId;

    /** Late charges after a folio is closed (FR-FO-038). */
    private string $lateChargerId;

    /** Guest requests (FR-FO-030): front desk staff who take and handle them, and someone who may only look. */
    private string $requestStaffId;

    private string $requestViewerId;

    /** Guest comments and complaints (FR-FO-031): two people who handle them (one can own what the other records) and one who may only look. */
    private string $feedbackStaffId;

    private string $feedbackStaff2Id;

    private string $feedbackViewerId;

    /** Front desk routines (FR-FO-032 to -034): a manager of checklists, two people who tick them, and the shift log's writer and reader. */
    private string $sopManagerId;

    private string $sopStaffId;

    private string $sopStaff2Id;

    private string $logWriterId;

    private string $logReaderId;

    /** Guest data corrections (FR-FO-039): someone who corrects names, and someone who may also correct identity. */
    private string $nameCorrectorId;

    private string $identityCorrectorId;

    private string $typeId;

    private string $planId;

    /** @var list<string> */
    private array $roomIds = [];

    private int $bookingKey = 0;

    private function buildHotel(): void
    {
        $this->clock = new AdjustableClock('2026-10-01 03:00:00');
        $this->app->instance(Clock::class, $this->clock);
        Context::add('correlation_id', '01arz3ndektsv4rrffq69g5fat');
        $this->createProperty(self::PROPERTY, 'Hotel');

        $admin = UserRecord::factory()->create();
        $manager = UserRecord::factory()->create();
        $viewer = UserRecord::factory()->create();
        $supervisor = UserRecord::factory()->create();
        $auditor = UserRecord::factory()->create();
        $hkSupervisor = UserRecord::factory()->create();
        $attendant = UserRecord::factory()->create();
        $attendant2 = UserRecord::factory()->create();
        $hkChief = UserRecord::factory()->create();
        $clerk = UserRecord::factory()->create();
        $analyst = UserRecord::factory()->create();
        $registrar = UserRecord::factory()->create();
        $dashOnly = UserRecord::factory()->create();
        $launderer = UserRecord::factory()->create();
        $laundryManager = UserRecord::factory()->create();
        $cashier = UserRecord::factory()->create();
        $cashier2 = UserRecord::factory()->create();
        $cashSupervisor = UserRecord::factory()->create();
        $rateManager = UserRecord::factory()->create();
        $lateCharger = UserRecord::factory()->create();
        $requestStaff = UserRecord::factory()->create();
        $requestViewer = UserRecord::factory()->create();
        $feedbackStaff = UserRecord::factory()->create();
        $feedbackStaff2 = UserRecord::factory()->create();
        $feedbackViewer = UserRecord::factory()->create();
        $sopManager = UserRecord::factory()->create();
        $sopStaff = UserRecord::factory()->create();
        $sopStaff2 = UserRecord::factory()->create();
        $logWriter = UserRecord::factory()->create();
        $logReader = UserRecord::factory()->create();
        $nameCorrector = UserRecord::factory()->create();
        $linenManager = UserRecord::factory()->create();
        $feePolicy = UserRecord::factory()->create();
        $feeClerk = UserRecord::factory()->create();
        $feeWaiver = UserRecord::factory()->create();
        $finance = UserRecord::factory()->create();
        $hkListManager = UserRecord::factory()->create();
        $hkListStaff = UserRecord::factory()->create();
        $hkListStaff2 = UserRecord::factory()->create();
        $hkListViewer = UserRecord::factory()->create();
        $linenManager2 = UserRecord::factory()->create();
        $linenLaundry = UserRecord::factory()->create();
        $identityCorrector = UserRecord::factory()->create();
        $this->grant($admin, self::PROPERTY, [
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
            InventoryAdminService::OVERBOOKING_PERMISSION, InventoryAdminService::BLOCK_PERMISSION, InventoryAdminService::HOLD_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION,
        ]);
        $this->grant($manager, self::PROPERTY, [ReservationService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, NightAuditService::RUN_PERMISSION, FolioService::MANAGE_PERMISSION, FolioService::CORRECT_PERMISSION, FolioService::REFUND_PERMISSION]);
        $this->grant($viewer, self::PROPERTY, [ReservationService::VIEW_PERMISSION, FolioService::VIEW_PERMISSION, StayService::VIEW_PERMISSION, NightAuditService::VIEW_PERMISSION]);
        $this->grant($hkSupervisor, self::PROPERTY, [HousekeepingService::MANAGE_PERMISSION, HousekeepingService::INSPECT_PERMISSION, HousekeepingService::VIEW_PERMISSION, HousekeepingService::SETTINGS_PERMISSION]);
        $this->grant($attendant, self::PROPERTY, [HousekeepingService::PERFORM_PERMISSION]);
        $this->grant($attendant2, self::PROPERTY, [HousekeepingService::PERFORM_PERMISSION]);
        $this->grant($hkChief, self::PROPERTY, [HousekeepingService::INSPECT_PERMISSION, HousekeepingService::WAIVE_PERMISSION]);
        $this->grant($analyst, self::PROPERTY, [DashboardService::VIEW_PERMISSION, DashboardService::REVENUE_PERMISSION, ReportService::VIEW_PERMISSION, ReportService::GUESTS_PERMISSION, ReportService::AUDIT_PERMISSION, ReportService::HOUSEKEEPING_PERMISSION, ObligationService::VIEW_PERMISSION]);
        $this->grant($finance, self::PROPERTY, [ObligationService::VIEW_PERMISSION, ObligationService::MANAGE_PERMISSION]);
        $this->grant($registrar, self::PROPERTY, [ReportService::GUESTS_PERMISSION, ReportService::GUESTS_EXPORT_PERMISSION, ReportService::IDENTITY_PERMISSION]);
        $this->grant($dashOnly, self::PROPERTY, [DashboardService::VIEW_PERMISSION]);
        $this->grant($cashier, self::PROPERTY, [CashierService::OPERATE_PERMISSION, FolioService::MANAGE_PERMISSION, FolioService::REFUND_PERMISSION, FolioService::CORRECT_PERMISSION]);
        $this->grant($cashier2, self::PROPERTY, [CashierService::OPERATE_PERMISSION, FolioService::MANAGE_PERMISSION]);
        $this->grant($rateManager, self::PROPERTY, [ReservationService::MANAGE_PERMISSION, RateChangeService::CHANGE_PERMISSION, FolioService::VIEW_PERMISSION]);
        $this->grant($lateCharger, self::PROPERTY, [LateChargeService::POST_PERMISSION, FolioService::MANAGE_PERMISSION]);
        $this->grant($requestStaff, self::PROPERTY, [GuestRequestService::MANAGE_PERMISSION]);
        $this->grant($requestViewer, self::PROPERTY, [GuestRequestService::VIEW_PERMISSION]);
        $this->grant($feedbackStaff, self::PROPERTY, [FeedbackService::MANAGE_PERMISSION]);
        $this->grant($feedbackStaff2, self::PROPERTY, [FeedbackService::MANAGE_PERMISSION]);
        $this->grant($feedbackViewer, self::PROPERTY, [FeedbackService::VIEW_PERMISSION]);
        $this->grant($sopManager, self::PROPERTY, [SopService::MANAGE_PERMISSION, SopService::VIEW_PERMISSION]);
        $this->grant($sopStaff, self::PROPERTY, [SopService::PERFORM_PERMISSION, ShiftLogService::WRITE_PERMISSION]);
        $this->grant($sopStaff2, self::PROPERTY, [SopService::PERFORM_PERMISSION]);
        $this->grant($logWriter, self::PROPERTY, [ShiftLogService::WRITE_PERMISSION]);
        $this->grant($logReader, self::PROPERTY, [ShiftLogService::READ_PERMISSION]);
        $this->grant($nameCorrector, self::PROPERTY, [GuestCorrectionService::CORRECT_PERMISSION, StayService::VIEW_PERMISSION]);
        $this->grant($identityCorrector, self::PROPERTY, [GuestCorrectionService::CORRECT_PERMISSION, StayService::VIEW_PERMISSION, StayService::IDENTITY_PERMISSION]);
        $this->grant($hkListManager, self::PROPERTY, [ChecklistService::MANAGE_PERMISSION, ChecklistService::VIEW_PERMISSION]);
        $this->grant($hkListStaff, self::PROPERTY, [ChecklistService::PERFORM_PERMISSION]);
        $this->grant($hkListStaff2, self::PROPERTY, [ChecklistService::PERFORM_PERMISSION]);
        $this->grant($hkListViewer, self::PROPERTY, [ChecklistService::VIEW_PERMISSION]);
        $this->grant($feePolicy, self::PROPERTY, [StayTimeFeeService::POLICY_PERMISSION]);
        $this->grant($feeClerk, self::PROPERTY, [StayTimeFeeService::APPLY_PERMISSION, StayService::VIEW_PERMISSION]);
        $this->grant($feeWaiver, self::PROPERTY, [StayTimeFeeService::WAIVE_PERMISSION, StayService::VIEW_PERMISSION]);
        $this->grant($linenManager, self::PROPERTY, [LinenService::MANAGE_PERMISSION]);
        $this->grant($linenManager2, self::PROPERTY, [LinenService::MANAGE_PERMISSION]);
        $this->grant($linenLaundry, self::PROPERTY, [LinenService::LAUNDRY_PERMISSION]);
        $this->grant($cashSupervisor, self::PROPERTY, [CashierService::VIEW_PERMISSION, CashierService::MANAGE_PERMISSION, CashierService::SETTINGS_PERMISSION]);
        $this->grant($clerk, self::PROPERTY, [LaundryService::INTAKE_PERMISSION, LaundryService::DELIVER_PERMISSION]);
        $this->grant($launderer, self::PROPERTY, [LaundryService::PROCESS_PERMISSION]);
        $this->grant($laundryManager, self::PROPERTY, [LaundryService::PRICES_PERMISSION, LaundryService::CANCEL_PERMISSION, LaundryService::VIEW_PERMISSION]);
        $this->grant($auditor, self::PROPERTY, [StayService::VIEW_PERMISSION, StayService::IDENTITY_PERMISSION]);
        $this->grant($supervisor, self::PROPERTY, ['front-office.folio.approve', NightAuditService::RUN_PERMISSION, NightAuditService::WAIVE_PERMISSION]);
        $this->adminId = strtolower((string) $admin->getKey());
        $this->managerId = strtolower((string) $manager->getKey());
        $this->viewerId = strtolower((string) $viewer->getKey());
        $this->supervisorId = strtolower((string) $supervisor->getKey());
        $this->auditorId = strtolower((string) $auditor->getKey());
        $this->hkSupervisorId = strtolower((string) $hkSupervisor->getKey());
        $this->attendantId = strtolower((string) $attendant->getKey());
        $this->attendant2Id = strtolower((string) $attendant2->getKey());
        $this->hkChiefId = strtolower((string) $hkChief->getKey());
        $this->clerkId = strtolower((string) $clerk->getKey());
        $this->laundererId = strtolower((string) $launderer->getKey());
        $this->laundryManagerId = strtolower((string) $laundryManager->getKey());
        $this->analystId = strtolower((string) $analyst->getKey());
        $this->registrarId = strtolower((string) $registrar->getKey());
        $this->dashOnlyId = strtolower((string) $dashOnly->getKey());
        $this->cashierId = strtolower((string) $cashier->getKey());
        $this->cashier2Id = strtolower((string) $cashier2->getKey());
        $this->sopManagerId = strtolower((string) $sopManager->getKey());
        $this->sopStaffId = strtolower((string) $sopStaff->getKey());
        $this->sopStaff2Id = strtolower((string) $sopStaff2->getKey());
        $this->nameCorrectorId = strtolower((string) $nameCorrector->getKey());
        $this->identityCorrectorId = strtolower((string) $identityCorrector->getKey());
        $this->logWriterId = strtolower((string) $logWriter->getKey());
        $this->logReaderId = strtolower((string) $logReader->getKey());
        $this->feedbackStaffId = strtolower((string) $feedbackStaff->getKey());
        $this->feedbackStaff2Id = strtolower((string) $feedbackStaff2->getKey());
        $this->feedbackViewerId = strtolower((string) $feedbackViewer->getKey());
        $this->requestStaffId = strtolower((string) $requestStaff->getKey());
        $this->requestViewerId = strtolower((string) $requestViewer->getKey());
        $this->lateChargerId = strtolower((string) $lateCharger->getKey());
        $this->rateManagerId = strtolower((string) $rateManager->getKey());
        $this->hkListManagerId = strtolower((string) $hkListManager->getKey());
        $this->hkListStaffId = strtolower((string) $hkListStaff->getKey());
        $this->hkListStaff2Id = strtolower((string) $hkListStaff2->getKey());
        $this->hkListViewerId = strtolower((string) $hkListViewer->getKey());
        $this->financeId = strtolower((string) $finance->getKey());
        $this->feePolicyId = strtolower((string) $feePolicy->getKey());
        $this->feeClerkId = strtolower((string) $feeClerk->getKey());
        $this->feeWaiverId = strtolower((string) $feeWaiver->getKey());
        $this->linenManagerId = strtolower((string) $linenManager->getKey());
        $this->linenManager2Id = strtolower((string) $linenManager2->getKey());
        $this->linenLaundryId = strtolower((string) $linenLaundry->getKey());
        $this->cashSupervisorId = strtolower((string) $cashSupervisor->getKey());
        app(PropertyContext::class)->activateFromString(self::PROPERTY);

        $catalog = app(RoomCatalogService::class);
        $this->typeId = $catalog->createType($this->property(), $this->adminId, 'DLX', 'Deluxe', null, 2, 1, 0, 'setup')->id;

        foreach (['101', '102', '103'] as $number) {
            $this->roomIds[] = $catalog->createRoom($this->property(), $this->adminId, $number, $this->typeId, '1', 'setup')->id;
        }

        app(ChargeSchemeService::class)->define($this->property(), $this->adminId, 'rooms', '2026-01-01', '10', '10', true, 'Regional regulation');
        $plans = app(RatePlanService::class);
        $this->planId = $plans->createPlan($this->property(), $this->adminId, 'BAR', 'Best Available', 'public', null, false, 'setup')->id;
        $plans->addPrice($this->property(), $this->adminId, $this->planId, $this->typeId, '2026-10-01', '2027-12-31', 127, 100_000_000, 'Season');
        app(PropertySettingsService::class)->initializeBusinessDate($this->property(), $this->adminId, '2026-10-01', 0, 'Go-live');
    }

    private function property(): PropertyId
    {
        return PropertyId::fromString(self::PROPERTY);
    }

    private function book(string $arrival = '2026-10-10', string $departure = '2026-10-12', string $status = 'tentative'): Reservation
    {
        return app(ReservationService::class)->create(
            $this->property(),
            $this->managerId,
            new ReservationRequest('direct', 'Budi Santoso', '+62 812 3456', 'budi@example.com', $arrival, $departure, 2, 0, $this->typeId, $this->planId, null, $status),
            IdempotencyKey::fromString(sprintf('hotel-%016d', ++$this->bookingKey)),
        );
    }
}
