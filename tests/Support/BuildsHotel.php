<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
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

    /** Reporting: an analyst (dashboard with revenue, reports, audit; identity masked), a registrar (guest reports and export, identity in clear), and a dashboard-only viewer. */
    private string $analystId;

    private string $registrarId;

    private string $dashOnlyId;

    /** Cashier shifts: two cashiers who can also take payments and refunds, and a cashier supervisor (review, close for others, the require-a-shift switch). */
    private string $cashierId;

    private string $cashier2Id;

    private string $cashSupervisorId;

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
        $this->grant($analyst, self::PROPERTY, [DashboardService::VIEW_PERMISSION, DashboardService::REVENUE_PERMISSION, ReportService::VIEW_PERMISSION, ReportService::GUESTS_PERMISSION, ReportService::AUDIT_PERMISSION]);
        $this->grant($registrar, self::PROPERTY, [ReportService::GUESTS_PERMISSION, ReportService::GUESTS_EXPORT_PERMISSION, ReportService::IDENTITY_PERMISSION]);
        $this->grant($dashOnly, self::PROPERTY, [DashboardService::VIEW_PERMISSION]);
        $this->grant($cashier, self::PROPERTY, [CashierService::OPERATE_PERMISSION, FolioService::MANAGE_PERMISSION, FolioService::REFUND_PERMISSION, FolioService::CORRECT_PERMISSION]);
        $this->grant($cashier2, self::PROPERTY, [CashierService::OPERATE_PERMISSION, FolioService::MANAGE_PERMISSION]);
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
