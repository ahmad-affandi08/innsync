<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Stays;

use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\FrontOffice\Domain\Stays\Stay;
use App\Modules\FrontOffice\Domain\Stays\StayStatus;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseStayRepository implements StayRepository
{
    public function add(PropertyId $property, Stay $stay, string $actorId): bool
    {
        try {
            // A savepoint keeps the surrounding transaction usable when the unique index refuses the second guest.
            DB::transaction(static function () use ($property, $stay, $actorId): void {
                DB::table('stays')->insert([
                    'id' => $stay->id, 'property_id' => $property->toString(), 'reservation_id' => $stay->reservationId, 'guest_id' => $stay->guestId,
                    'room_id' => $stay->roomId, 'status' => $stay->status->value, 'adults' => $stay->adults, 'children' => $stay->children,
                    'checked_in_business_date' => $stay->checkedInDate->toString(), 'checked_in_at' => $stay->checkedInAt, 'checked_in_by' => $actorId,
                    'expected_departure' => $stay->expectedDeparture->toString(), 'lock_version' => 0,
                    'created_at' => $stay->checkedInAt, 'updated_at' => $stay->checkedInAt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function find(PropertyId $property, string $id): ?Stay
    {
        $row = DB::table('stays')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByReservation(PropertyId $property, string $reservationId): ?Stay
    {
        $row = DB::table('stays')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function inHouse(PropertyId $property): array
    {
        return DB::table('stays')->where('property_id', $property->toString())->where('status', 'in_house')->orderBy('expected_departure')->get()
            ->map(static fn (stdClass $r): Stay => self::hydrate($r))->all();
    }

    public function roomIsOccupied(PropertyId $property, string $roomId): bool
    {
        return DB::table('stays')->where('property_id', $property->toString())->where('room_id', $roomId)->where('status', 'in_house')->lockForUpdate()->exists();
    }

    public function checkOut(PropertyId $property, Stay $stay, int $expectedLockVersion, BusinessDate $date, string $actorId, DateTimeImmutable $at): bool
    {
        return DB::table('stays')->where('property_id', $property->toString())->where('id', $stay->id)->where('lock_version', $expectedLockVersion)->where('status', 'in_house')->update([
            'status' => 'checked_out', 'checked_out_business_date' => $date->toString(), 'checked_out_at' => $at, 'checked_out_by' => $actorId,
            'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at,
        ]) === 1;
    }

    public function moveRoom(PropertyId $property, string $stayId, string $toRoomId, int $expectedLockVersion): bool
    {
        try {
            return DB::transaction(static fn (): bool => DB::table('stays')->where('property_id', $property->toString())->where('id', $stayId)->where('lock_version', $expectedLockVersion)->where('status', 'in_house')
                ->update(['room_id' => $toRoomId, 'lock_version' => $expectedLockVersion + 1]) === 1);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function addRoomMove(PropertyId $property, string $id, string $stayId, string $fromRoomId, string $toRoomId, string $fromTypeId, string $toTypeId, BusinessDate $date, string $reason, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('stay_room_moves')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'stay_id' => $stayId, 'from_room_id' => $fromRoomId, 'to_room_id' => $toRoomId, 'from_type_id' => $fromTypeId, 'to_type_id' => $toTypeId,
            'business_date' => $date->toString(), 'reason' => $reason, 'moved_by' => $actorId, 'moved_at' => $at,
        ]);
    }

    public function roomMoves(PropertyId $property, string $stayId): array
    {
        return DB::table('stay_room_moves')->where('property_id', $property->toString())->where('stay_id', $stayId)->orderBy('moved_at')->orderBy('id')->get()
            ->map(static fn ($m): array => ['id' => $m->id, 'from_room_id' => $m->from_room_id, 'to_room_id' => $m->to_room_id, 'reason' => $m->reason, 'business_date' => substr((string) $m->business_date, 0, 10), 'moved_at' => (string) $m->moved_at])->all();
    }

    public function extend(PropertyId $property, string $stayId, BusinessDate $newDeparture, int $expectedLockVersion): bool
    {
        return DB::table('stays')->where('property_id', $property->toString())->where('id', $stayId)->where('lock_version', $expectedLockVersion)->where('status', 'in_house')
            ->update(['expected_departure' => $newDeparture->toString(), 'lock_version' => $expectedLockVersion + 1]) === 1;
    }

    public function attachIdPhoto(PropertyId $property, string $stayId, string $fileId, int $expectedLockVersion): bool
    {
        return DB::table('stays')->where('property_id', $property->toString())->where('id', $stayId)->where('lock_version', $expectedLockVersion)->where('status', 'in_house')->update([
            'id_photo_file_id' => $fileId, 'lock_version' => $expectedLockVersion + 1,
        ]) === 1;
    }

    private static function hydrate(stdClass $r): Stay
    {
        return new Stay(
            $r->id, $r->reservation_id, $r->guest_id, $r->room_id, StayStatus::from($r->status), (int) $r->adults, (int) $r->children,
            BusinessDate::fromString(substr((string) $r->checked_in_business_date, 0, 10)),
            new DateTimeImmutable((string) $r->checked_in_at, new DateTimeZone('UTC')),
            BusinessDate::fromString(substr((string) $r->expected_departure, 0, 10)),
            $r->checked_out_business_date === null ? null : BusinessDate::fromString(substr((string) $r->checked_out_business_date, 0, 10)),
            $r->id_photo_file_id, (int) $r->lock_version,
        );
    }
}
