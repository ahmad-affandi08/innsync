<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;

/**
 * Par levels of linen and amenities (FR-HK-019). For each item the hotel says, per room type, how many the floor should hold for
 * each room of the type (the par) and how many one room service normally uses (the standard); and, for an area such as the lobby or
 * the restaurant, how many the floor should hold there. From that the page shows what to bring to the floor (the par of all rooms
 * and areas less what the floor holds, and how much of it the store can cover) and, for a shift, how much was used against the
 * standard times the rooms serviced in it. The shifts are the baseline of Indonesian hotels, 07:00 to 15:00, 15:00 to 23:00 and 23:00
 * to 07:00 the next morning in the property's time, which the owner confirms.
 */
final readonly class ParLevelService
{
    public const MANAGE_PERMISSION = 'housekeeping.par.manage';

    /** @var array<string, array{0: string, 1: string}> the local start and end of each shift; the night ends the next morning */
    public const SHIFTS = ['morning' => ['07:00', '15:00'], 'afternoon' => ['15:00', '23:00'], 'night' => ['23:00', '07:00']];

    public const MAX_PAR = 10_000;

    public function __construct(
        private ParLevelRepository $levels,
        private LinenRepository $linen,
        private RoomCatalogReader $rooms,
        private PropertyTimeZoneReader $zones,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorizeView($property, $actorId);
        $types = $this->roomTypes($property);
        $levels = $this->levels->all($property);
        $balances = $this->linen->balances($property);
        $items = array_values(array_filter($this->linen->items($property), static fn (array $i): bool => $i['is_active']));
        $replenishment = [];

        foreach ($items as $item) {
            $target = 0;

            foreach ($levels as $level) {
                if ($level['item_id'] !== $item['id']) {
                    continue;
                }

                $target += $level['scope_kind'] === 'room_type' ? $level['par_quantity'] * ($types[$level['room_type_id']]['rooms'] ?? 0) : $level['par_quantity'];
            }

            if ($target === 0) {
                continue;
            }

            $floor = $balances[$item['id']]['locations']['floor'] ?? 0;
            $store = $balances[$item['id']]['locations']['store'] ?? 0;
            $need = max(0, $target - $floor);
            $replenishment[] = ['item_id' => $item['id'], 'code' => $item['code'], 'name' => $item['name'], 'unit' => $item['unit'], 'target' => $target, 'on_floor' => $floor, 'need' => $need, 'in_store' => $store, 'from_store' => min($need, $store), 'to_obtain' => max(0, $need - $store)];
        }

        return [
            'items' => array_map(static fn (array $i): array => ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'kind' => $i['kind'], 'unit' => $i['unit']], $items),
            'room_types' => array_values($types), 'areas' => $this->levels->areas($property), 'levels' => $levels, 'replenishment' => $replenishment,
            'shifts' => array_map(static fn (string $s, array $w): array => ['code' => $s, 'from' => $w[0], 'to' => $w[1]], array_keys(self::SHIFTS), array_values(self::SHIFTS)),
            'business_date' => $this->businessDate->current($property)->toString(),
            'may' => ['manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)],
        ];
    }

    /**
     * Sets the par and the standard use of an item for a room type or an area, with a reason.
     *
     * @return array<string, mixed>
     */
    public function save(PropertyId $property, string $actorId, string $itemId, string $scopeKind, string $scopeRef, int $par, int $use, ?int $lock, string $reason): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not set par levels.');
        }

        $item = $this->linen->findItem($property, strtolower($itemId));
        $scopeRef = $scopeKind === 'area' ? trim($scopeRef) : strtolower(trim($scopeRef));

        if ($item === null || ! $item['is_active']) {
            throw Refusal::invalid('Choose an active item.', ['item_id']);
        }

        if (! in_array($scopeKind, ['room_type', 'area'], true)) {
            throw Refusal::invalid('Choose a room type or an area.', ['scope_kind']);
        }

        if ($scopeKind === 'room_type' && $this->rooms->type($property, $scopeRef) === null) {
            throw Refusal::invalid('Choose a room type of this property.', ['scope_ref']);
        }

        if ($scopeKind === 'area' && ($scopeRef === '' || mb_strlen($scopeRef) > 40)) {
            throw Refusal::invalid('Name the area in at most 40 characters.', ['scope_ref']);
        }

        if ($par < 0 || $par > self::MAX_PAR || $use < 0 || $use > self::MAX_PAR || ($scopeKind === 'area' && $use !== 0)) {
            throw Refusal::invalid(sprintf('The par and the use are whole numbers from 0 to %d; an area has no standard use.', self::MAX_PAR), ['par_quantity', 'use_quantity']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $before = $this->levels->find($property, $item['id'], $scopeKind, $scopeRef);

        if (($before === null) !== ($lock === null)) {
            throw Refusal::stateConflict($before === null ? 'This par level does not exist yet.' : 'This par level already exists; open it again to change it.');
        }

        $this->transactions->run(function () use ($property, $actorId, $item, $scopeKind, $scopeRef, $par, $use, $lock, $reason, $before): void {
            if (! $this->levels->save($property, $this->ids->next(), $item['id'], $scopeKind, $scopeRef, $par, $use, $lock, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This par level changed after you opened it.');
            }

            $saved = $this->levels->find($property, $item['id'], $scopeKind, $scopeRef) ?? throw Refusal::notFound('Par level not found.');
            $this->audit->record(new AuditEntry(
                $property->toString(), strtolower($actorId), 'par_level.saved', 'linen_par_level', $saved['id'],
                $before === null ? null : ['par' => $before['par_quantity'], 'use' => $before['use_quantity']], ['item' => $item['code'], 'scope' => $scopeKind, 'ref' => $scopeRef, 'par' => $par, 'use' => $use], trim($reason),
            ));
        });

        return $this->levels->find($property, $item['id'], $scopeKind, $scopeRef) ?? throw Refusal::notFound('Par level not found.');
    }

    /**
     * What was used in a shift against the standard times the rooms serviced in it, by item and room type.
     *
     * @return array{date: string, shift: string, from: string, to: string, rows: list<array<string, mixed>>}
     */
    public function consumption(PropertyId $property, string $actorId, ?string $date, string $shift): array
    {
        $this->authorizeView($property, $actorId);

        if (! isset(self::SHIFTS[$shift])) {
            throw Refusal::invalid('Choose the morning, afternoon or night shift.', ['shift']);
        }

        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');

        try {
            $day = CalendarDate::fromString($date === null || $date === '' ? $zone->calendarDateAt($this->clock->nowUtc())->toString() : $date);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['date']);
        }

        [$start, $end] = self::SHIFTS[$shift];
        $from = $zone->utcAt($day, $start);
        $to = $zone->utcAt($shift === 'night' ? $day->addDays(1) : $day, $end);
        $types = $this->roomTypes($property);
        $serviced = $this->levels->servicedRooms($property, $from, $to);
        $used = $this->levels->usedIn($property, $from, $to);
        $rows = [];

        foreach ($this->levels->all($property) as $level) {
            if ($level['scope_kind'] !== 'room_type') {
                continue;
            }

            $actual = $used[$level['item_id']][$level['room_type_id']] ?? 0;
            $rooms = $serviced[$level['room_type_id']] ?? 0;
            $expected = $level['use_quantity'] * $rooms;

            if ($level['use_quantity'] === 0 && $actual === 0) {
                continue;
            }

            $rows[] = [
                'item_id' => $level['item_id'], 'code' => $level['item_code'], 'name' => $level['item_name'], 'room_type_id' => $level['room_type_id'], 'room_type' => $types[$level['room_type_id']]['code'] ?? '',
                'rooms_serviced' => $rooms, 'standard' => $level['use_quantity'], 'expected' => $expected, 'actual' => $actual, 'variance' => $actual - $expected,
            ];
        }

        return ['date' => $day->toString(), 'shift' => $shift, 'from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z'), 'rows' => $rows];
    }

    /** @return array<string, array{id: string, code: string, name: string, rooms: int}> */
    private function roomTypes(PropertyId $property): array
    {
        $types = [];

        foreach ($this->rooms->activeTypes($property) as $type) {
            $types[$type->id] = ['id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'rooms' => 0];
        }

        foreach ($this->rooms->activeRooms($property) as $room) {
            if ($room->isActive && isset($types[$room->roomTypeId])) {
                $types[$room->roomTypeId]['rooms']++;
            }
        }

        return $types;
    }

    private function authorizeView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        foreach ([self::MANAGE_PERMISSION, LinenService::MANAGE_PERMISSION, HousekeepingService::VIEW_PERMISSION] as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see par levels.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
