<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Inventory;

use App\Modules\FrontOffice\Application\Inventory\InventoryRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseInventoryRepository implements InventoryRepository
{
    public function lockRoomType(PropertyId $property, string $roomTypeId): void
    {
        DB::table('room_types')->where('property_id', $property->toString())->where('id', $roomTypeId)->lockForUpdate()->first();
    }

    public function soldByNight(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to, bool $locking = false): array
    {
        $counts = [];

        foreach (DB::table('reservation_nights')
            ->when($locking, static fn ($q) => $q->sharedLock())
            ->selectRaw('night, COUNT(*) AS n')
            ->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->where('is_active', true)
            ->whereBetween('night', [$from->toString(), $to->toString()])
            ->groupBy('night')->get() as $row) {
            $counts[substr((string) $row->night, 0, 10)] = (int) $row->n;
        }

        return $counts;
    }

    public function blockedByNight(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to, bool $locking = false): array
    {
        $rows = DB::table('room_blocks')
            ->when($locking, static fn ($q) => $q->sharedLock())
            ->join('rooms', 'rooms.id', '=', 'room_blocks.room_id')
            ->where('room_blocks.property_id', $property->toString())->where('rooms.room_type_id', $roomTypeId)->where('rooms.is_active', true)
            ->whereNull('room_blocks.released_at')
            ->where('room_blocks.start_date', '<=', $to->toString())->where('room_blocks.end_date', '>=', $from->toString())
            ->get(['room_blocks.start_date', 'room_blocks.end_date']);

        return self::spread($rows->map(static fn ($r): array => [substr((string) $r->start_date, 0, 10), substr((string) $r->end_date, 0, 10), 1])->all(), $from, $to);
    }

    public function heldByNight(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to, DateTimeImmutable $now, bool $locking = false): array
    {
        $rows = DB::table('inventory_holds')
            ->when($locking, static fn ($q) => $q->sharedLock())
            ->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->whereNull('released_at')
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now->format('Y-m-d H:i:s.u')))
            ->where('start_date', '<=', $to->toString())->where('end_date', '>=', $from->toString())
            ->get(['start_date', 'end_date', 'rooms']);

        return self::spread($rows->map(static fn ($r): array => [substr((string) $r->start_date, 0, 10), substr((string) $r->end_date, 0, 10), (int) $r->rooms])->all(), $from, $to);
    }

    public function overbookingAllowance(PropertyId $property, string $roomTypeId, bool $locking = false): int
    {
        return (int) DB::table('inventory_policies')
            ->when($locking, static fn ($q) => $q->sharedLock())->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->value('overbooking_allowance_rooms');
    }

    public function allowanceLockVersion(PropertyId $property, string $roomTypeId): int
    {
        return (int) DB::table('inventory_policies')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->value('lock_version');
    }

    public function setOverbookingAllowance(PropertyId $property, string $roomTypeId, int $rooms, string $actorId, int $expectedLockVersion): int
    {
        $exists = DB::table('inventory_policies')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->exists();

        if (! $exists) {
            if ($expectedLockVersion !== 0) {
                return -1;
            }

            try {
                DB::table('inventory_policies')->insert([
                    'property_id' => $property->toString(), 'room_type_id' => $roomTypeId, 'overbooking_allowance_rooms' => $rooms,
                    'updated_by' => $actorId, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return -1;
            }

            return 0;
        }

        $updated = DB::table('inventory_policies')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->where('lock_version', $expectedLockVersion)
            ->update(['overbooking_allowance_rooms' => $rooms, 'updated_by' => $actorId, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => now()]);

        return $updated === 1 ? $expectedLockVersion + 1 : -1;
    }

    /**
     * Adds `count` to every night of each range that falls inside the window.
     *
     * @param  list<array{string, string, int}>  $ranges  start, end (inclusive), count
     * @return array<string, int>
     */
    private static function spread(array $ranges, BusinessDate $from, BusinessDate $to): array
    {
        $counts = [];

        foreach ($ranges as [$start, $end, $count]) {
            $night = max($start, $from->toString()) === $start ? BusinessDate::fromString($start) : $from;
            $last = min($end, $to->toString()) === $end ? BusinessDate::fromString($end) : $to;

            for (; ! $night->isAfter($last); $night = $night->next()) {
                $counts[$night->toString()] = ($counts[$night->toString()] ?? 0) + $count;
            }
        }

        return $counts;
    }
}
