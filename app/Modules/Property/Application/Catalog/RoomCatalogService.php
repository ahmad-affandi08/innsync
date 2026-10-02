<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Modules\Property\Domain\Catalog\Room;
use App\Modules\Property\Domain\Catalog\RoomNumber;
use App\Modules\Property\Domain\Catalog\RoomType;
use App\Modules\Property\Domain\Catalog\RoomTypeCode;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

/**
 * Room types and rooms (the room master). Rooms and types are never deleted: a retired one is deactivated, so history
 * that points at it stays readable (BR-003). Every change needs the permission and a reason and is audited.
 */
final readonly class RoomCatalogService implements RoomCatalogReader
{
    public const MANAGE_PERMISSION = 'property.catalog.manage';

    public const VIEW_PERMISSION = 'property.catalog.view';

    public function __construct(
        private RoomCatalogRepository $catalog,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private PropertyContext $property,
    ) {}

    // ---- reads for other contexts (no permission: callers authorize their own use) ----

    public function activeTypes(PropertyId $property): array
    {
        $this->assertProperty($property);

        return array_values(array_map(
            static fn (RoomType $t): RoomTypeView => self::typeView($t),
            array_filter($this->catalog->types($property), static fn (RoomType $t): bool => $t->isActive),
        ));
    }

    public function activeRooms(PropertyId $property): array
    {
        $this->assertProperty($property);

        return array_values(array_map(
            static fn (Room $r): RoomView => self::roomView($r),
            array_filter($this->catalog->rooms($property), static fn (Room $r): bool => $r->isActive),
        ));
    }

    public function type(PropertyId $property, string $id): ?RoomTypeView
    {
        $this->assertProperty($property);
        $type = $this->catalog->findType($property, strtolower($id));

        return $type === null ? null : self::typeView($type);
    }

    public function room(PropertyId $property, string $id): ?RoomView
    {
        $this->assertProperty($property);
        $room = $this->catalog->findRoom($property, strtolower($id));

        return $room === null ? null : self::roomView($room);
    }

    private static function typeView(RoomType $t): RoomTypeView
    {
        return new RoomTypeView($t->id, $t->code->value, $t->name, $t->maxAdults, $t->maxChildren, $t->isActive);
    }

    private static function roomView(Room $r): RoomView
    {
        return new RoomView($r->id, $r->number->value, $r->roomTypeId, $r->floor, $r->isActive, $r->building);
    }

    // ---- reads for the admin screens ----

    /** @return list<RoomType> */
    public function listTypes(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return $this->catalog->types($property);
    }

    /** @return list<Room> */
    public function listRooms(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return $this->catalog->rooms($property);
    }

    // ---- room types ----

    public function createType(PropertyId $property, string $actorId, string $code, string $name, ?string $description, int $maxAdults, int $maxChildren, int $sortOrder, string $reason): RoomType
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);

        try {
            $type = new RoomType($this->ids->next(), RoomTypeCode::fromString($code), trim($name), $description === null || trim($description) === '' ? null : trim($description), $maxAdults, $maxChildren, $sortOrder, true, 0);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['code', 'name', 'max_adults', 'max_children']);
        }

        $this->transactions->run(function () use ($property, $actorId, $type, $reason): void {
            if (! $this->catalog->addType($property, $type)) {
                throw Refusal::invalid('A room type with this code already exists.', ['code']);
            }

            $this->record($property, $actorId, 'room_type.created', 'room_type', $type->id, null, $type->toArray(), $reason);
        });

        return $type;
    }

    public function updateType(PropertyId $property, string $actorId, string $id, string $name, ?string $description, int $maxAdults, int $maxChildren, int $sortOrder, int $expectedLockVersion, string $reason): RoomType
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $before = $this->catalog->findType($property, strtolower($id)) ?? throw Refusal::notFound('Room type not found.');

        try {
            $after = $before->revised($name, $description === null || trim($description) === '' ? null : $description, $maxAdults, $maxChildren, $sortOrder);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['name', 'max_adults', 'max_children']);
        }

        return $this->saveType($property, $actorId, $before, $after, $expectedLockVersion, 'room_type.updated', $reason);
    }

    public function setTypeActive(PropertyId $property, string $actorId, string $id, bool $active, int $expectedLockVersion, string $reason): RoomType
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $before = $this->catalog->findType($property, strtolower($id)) ?? throw Refusal::notFound('Room type not found.');

        if (! $active && $this->catalog->activeRoomCount($property, $before->id) > 0) {
            throw Refusal::stateConflict('Deactivate or move the active rooms of this type first.');
        }

        return $this->saveType($property, $actorId, $before, $before->withActive($active), $expectedLockVersion, $active ? 'room_type.activated' : 'room_type.deactivated', $reason);
    }

    // ---- rooms ----

    public function createRoom(PropertyId $property, string $actorId, string $number, string $roomTypeId, ?string $floor, string $reason, ?string $building = null): Room
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $type = $this->catalog->findType($property, strtolower($roomTypeId));

        if ($type === null || ! $type->isActive) {
            throw Refusal::invalid('Choose an active room type.', ['room_type_id']);
        }

        try {
            $room = new Room($this->ids->next(), RoomNumber::fromString($number), $type->id, $floor === null || trim($floor) === '' ? null : trim($floor), true, 0, $building === null || trim($building) === '' ? null : trim($building));
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['number', 'floor', 'building']);
        }

        $this->transactions->run(function () use ($property, $actorId, $room, $reason): void {
            if (! $this->catalog->addRoom($property, $room)) {
                throw Refusal::invalid('A room with this number already exists.', ['number']);
            }

            $this->record($property, $actorId, 'room.created', 'room', $room->id, null, $room->toArray(), $reason);
        });

        return $room;
    }

    public function updateRoom(PropertyId $property, string $actorId, string $id, string $roomTypeId, ?string $floor, int $expectedLockVersion, string $reason, ?string $building = null): Room
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $before = $this->catalog->findRoom($property, strtolower($id)) ?? throw Refusal::notFound('Room not found.');
        $type = $this->catalog->findType($property, strtolower($roomTypeId));

        if ($type === null || ! $type->isActive) {
            throw Refusal::invalid('Choose an active room type.', ['room_type_id']);
        }

        try {
            $after = $before->moved($type->id, $floor, $building);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['floor', 'building']);
        }

        return $this->saveRoom($property, $actorId, $before, $after, $expectedLockVersion, 'room.updated', $reason);
    }

    public function setRoomActive(PropertyId $property, string $actorId, string $id, bool $active, int $expectedLockVersion, string $reason): Room
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $before = $this->catalog->findRoom($property, strtolower($id)) ?? throw Refusal::notFound('Room not found.');

        if ($active) {
            $type = $this->catalog->findType($property, $before->roomTypeId);

            if ($type === null || ! $type->isActive) {
                throw Refusal::stateConflict('Activate the room type first.');
            }
        }

        return $this->saveRoom($property, $actorId, $before, $before->withActive($active), $expectedLockVersion, $active ? 'room.activated' : 'room.deactivated', $reason);
    }

    private function saveType(PropertyId $property, string $actorId, RoomType $before, RoomType $after, int $expected, string $action, string $reason): RoomType
    {
        $this->transactions->run(function () use ($property, $actorId, $before, $after, $expected, $action, $reason): void {
            if ($before->lockVersion !== $expected || ! $this->catalog->saveType($property, $after, $expected)) {
                throw Refusal::stateConflict('This room type changed after you opened it.');
            }

            $this->record($property, $actorId, $action, 'room_type', $before->id, $before->toArray(), $after->toArray(), $reason);
        });

        return $this->catalog->findType($property, $before->id) ?? $after;
    }

    private function saveRoom(PropertyId $property, string $actorId, Room $before, Room $after, int $expected, string $action, string $reason): Room
    {
        $this->transactions->run(function () use ($property, $actorId, $before, $after, $expected, $action, $reason): void {
            if ($before->lockVersion !== $expected || ! $this->catalog->saveRoom($property, $after, $expected)) {
                throw Refusal::stateConflict('This room changed after you opened it.');
            }

            $this->record($property, $actorId, $action, 'room', $before->id, $before->toArray(), $after->toArray(), $reason);
        });

        return $this->catalog->findRoom($property, $before->id) ?? $after;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(PropertyId $property, string $actorId, string $action, string $type, string $id, ?array $before, ?array $after, string $reason): void
    {
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $action, $type, $id, $before, $after, trim($reason)));
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw Refusal::invalid('A change needs a reason of at most 500 characters.', ['reason']);
        }
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $this->assertProperty($property);

        $allowed = $this->permissions->allowsInProperty($actorId, $permission, $property)
            || ($permission === self::VIEW_PERMISSION && $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property));

        if (! $allowed) {
            throw Refusal::forbidden('This person may not use the room catalogue.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
