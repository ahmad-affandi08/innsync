<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\DayUse;

use App\Modules\FrontOffice\Application\DayUse\DayUseStore;
use App\Modules\FrontOffice\Application\DayUse\DayUseTimes;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDayUseStore implements DayUseStore
{
    public function rates(PropertyId $property): array
    {
        return DB::table('day_use_rates')->where('property_id', $property->toString())->orderByDesc('is_active')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function rate(PropertyId $property, string $id): ?array
    {
        $row = DB::table('day_use_rates')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function rateOfType(PropertyId $property, string $roomTypeId): ?array
    {
        $row = DB::table('day_use_rates')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->first();

        return $row === null ? null : (array) $row;
    }

    public function addRate(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('day_use_rates')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function updateRate(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('day_use_rates')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addBooking(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('day_use_bookings')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'booked', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function booking(PropertyId $property, string $id): ?array
    {
        $row = DB::table('day_use_bookings')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function updateBooking(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('day_use_bookings')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function bookings(PropertyId $property, string $since, int $limit): array
    {
        return DB::table('day_use_bookings')->where('property_id', $property->toString())->where(fn ($q) => $q->where('starts_on', '>=', $since)->orWhereIn('status', ['booked', 'in_use']))->orderBy('starts_on')->orderBy('starts_at')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function holdingRoom(PropertyId $property, string $roomId, string $day): array
    {
        $out = [];

        foreach (DB::table('day_use_bookings')->where('property_id', $property->toString())->where('room_id', $roomId)->where('starts_on', $day)->whereIn('status', ['booked', 'in_use', 'done'])->get(['starts_at', 'ends_at']) as $r) {
            $out[] = ['start' => (int) DayUseTimes::minutes((string) $r->starts_at), 'end' => (int) DayUseTimes::minutes((string) $r->ends_at)];
        }

        return $out;
    }

    public function inUseUpTo(PropertyId $property, string $day, int $limit): array
    {
        return DB::table('day_use_bookings')->where('property_id', $property->toString())->where('status', 'in_use')->where('starts_on', '<=', $day)->orderBy('number')->limit($limit)->pluck('number')->map(static fn ($n): string => (string) $n)->all();
    }

    public function stayDueOut(PropertyId $property, string $roomId): ?string
    {
        $due = DB::table('stays')->where('property_id', $property->toString())->where('room_id', $roomId)->where('status', 'in_house')->value('expected_departure');

        return is_string($due) ? substr($due, 0, 10) : null;
    }

    public function reservedForNight(PropertyId $property, string $roomId, string $day): bool
    {
        return DB::table('reservations')->where('property_id', $property->toString())->where('room_id', $roomId)->whereIn('status', ['tentative', 'confirmed', 'guaranteed'])->where('arrival_date', '<=', $day)->where('departure_date', '>', $day)->exists();
    }

    public function openShiftOf(PropertyId $property, string $cashierId): ?string
    {
        $id = DB::table('cashier_shifts')->where('property_id', $property->toString())->where('cashier_id', $cashierId)->where('status', 'open')->value('id');

        return is_string($id) ? $id : null;
    }
}
