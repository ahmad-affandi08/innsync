<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
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
        $this->grant($admin, self::PROPERTY, [
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
            InventoryAdminService::OVERBOOKING_PERMISSION, InventoryAdminService::BLOCK_PERMISSION, InventoryAdminService::HOLD_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION,
        ]);
        $this->grant($manager, self::PROPERTY, [ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, FolioService::CORRECT_PERMISSION, FolioService::REFUND_PERMISSION]);
        $this->grant($viewer, self::PROPERTY, [ReservationService::VIEW_PERMISSION, FolioService::VIEW_PERMISSION]);
        $this->grant($supervisor, self::PROPERTY, ['front-office.folio.approve']);
        $this->adminId = strtolower((string) $admin->getKey());
        $this->managerId = strtolower((string) $manager->getKey());
        $this->viewerId = strtolower((string) $viewer->getKey());
        $this->supervisorId = strtolower((string) $supervisor->getKey());
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
