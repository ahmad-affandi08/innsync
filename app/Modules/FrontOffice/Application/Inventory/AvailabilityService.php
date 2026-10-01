<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\RatePlanReader;
use App\Modules\Property\Application\Rates\RestrictionCalendar;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;
use InvalidArgumentException;

/**
 * How many rooms of each type can still be sold on each night (FR-FO-002, FR-FO-007): the active rooms, minus rooms out of
 * order or out of service, minus allotments and holds, minus rooms already promised to reservations. A negative number is an
 * oversold night. The calendar horizon is a property setting (default 365 days).
 */
final readonly class AvailabilityService
{
    public const VIEW_PERMISSION = 'front-office.availability.view';

    public function __construct(
        private InventoryRepository $inventory,
        private RoomCatalogReader $rooms,
        private RatePlanReader $plans,
        private RestrictionCalendar $restrictions,
        private BusinessDateProvider $businessDate,
        private PropertySettingsService $settings,
        private PermissionChecker $permissions,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * The calendar for the active room types from `$from`, for `$days` nights (at most the property's horizon).
     * With a rate plan, each night also carries that plan's selling markers.
     *
     * @return array{from: string, days: int, types: list<array<string, mixed>>}
     */
    public function calendar(PropertyId $property, string $actorId, ?string $from, int $days, ?string $ratePlanId): array
    {
        $this->authorize($property, $actorId);
        $horizon = $this->settings->get($property)->availabilityHorizonDays;

        if ($days < 1 || $days > min($horizon, 366)) {
            throw Refusal::invalid("Choose between 1 and {$horizon} days.", ['days']);
        }

        try {
            $start = $from === null || $from === '' ? $this->businessDate->current($property) : BusinessDate::fromString($from);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from']);
        }

        $end = $start->addDays($days - 1);
        $types = [];

        foreach ($this->rooms->activeTypes($property) as $type) {
            $nights = $this->nights($property, $type->id, $start, $end);
            $flags = $ratePlanId === null || $ratePlanId === '' ? [] : $this->restrictions->flags($property, $ratePlanId, $type->id, $start, $days);
            $rows = [];

            foreach ($nights as $date => $availability) {
                $rows[] = ['date' => $date, ...$availability->toArray(), 'restrictions' => $flags[$date] ?? null];
            }

            $types[] = ['id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'nights' => $rows];
        }

        return ['from' => $start->toString(), 'days' => $days, 'types' => $types];
    }

    /** Rate plans whose selling markers the calendar can show. @return list<array{id: string, code: string, name: string}> */
    public function plans(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);

        return array_map(static fn ($p): array => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name], $this->plans->activePlans($property));
    }

    /**
     * Availability of one room type for each night of a stay. No permission check: used by the reservation service,
     * which has authorized its caller. By default the counts are locking reads, for decisions taken under the room type's
     * lock; pass `$locking = false` for an advisory look that must not take locks.
     *
     * @return array<string, Availability>
     */
    public function forStay(PropertyId $property, string $roomTypeId, StayDates $stay, bool $locking = true): array
    {
        $this->assertProperty($property);

        // Used to decide a booking, a block or a hold under the room type's lock, so the counts must be the latest committed ones.
        return $this->nights($property, $roomTypeId, $stay->arrival, $stay->departure->previous(), $locking);
    }

    /** @return array<string, Availability> keyed by night */
    private function nights(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to, bool $locking = false): array
    {
        $total = count(array_filter($this->rooms->activeRooms($property), static fn ($r): bool => $r->roomTypeId === $roomTypeId));
        $sold = $this->inventory->soldByNight($property, $roomTypeId, $from, $to, $locking);
        $blocked = $this->inventory->blockedByNight($property, $roomTypeId, $from, $to, $locking);
        $held = $this->inventory->heldByNight($property, $roomTypeId, $from, $to, $this->clock->nowUtc(), $locking);
        $allowance = $this->inventory->overbookingAllowance($property, $roomTypeId, $locking);
        $result = [];

        for ($night = $from; ! $night->isAfter($to); $night = $night->next()) {
            $key = $night->toString();
            $result[$key] = new Availability($total, $blocked[$key] ?? 0, $held[$key] ?? 0, $sold[$key] ?? 0, $allowance);
        }

        return $result;
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        foreach ([self::VIEW_PERMISSION, 'front-office.reservation.view', 'front-office.reservation.manage'] as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see availability.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
