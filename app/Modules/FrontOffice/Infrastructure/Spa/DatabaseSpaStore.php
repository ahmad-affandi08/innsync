<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Spa;

use App\Modules\FrontOffice\Application\Spa\SpaStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DatabaseSpaStore implements SpaStore
{
    private const HOLDING = ['booked', 'accepted', 'in_progress'];

    public function rooms(PropertyId $property): array
    {
        return DB::table('spa_rooms')->where('property_id', $property->toString())->orderBy('code')->get()->map(static fn ($r): array => self::flags((array) $r))->all();
    }

    public function room(PropertyId $property, string $id): ?array
    {
        $r = DB::table('spa_rooms')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : self::flags((array) $r);
    }

    public function addRoom(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('spa_rooms', $property, $row, $at);
    }

    public function updateRoom(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return $this->update('spa_rooms', $property, $id, $lock, $fields, $at);
    }

    public function treatments(PropertyId $property): array
    {
        return DB::table('spa_treatments')->where('property_id', $property->toString())->orderBy('name')->get()->map(static fn ($r): array => self::flags((array) $r))->all();
    }

    public function treatment(PropertyId $property, string $id): ?array
    {
        $r = DB::table('spa_treatments')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : self::flags((array) $r);
    }

    public function addTreatment(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('spa_treatments', $property, $row, $at);
    }

    public function updateTreatment(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return $this->update('spa_treatments', $property, $id, $lock, $fields, $at);
    }

    public function therapists(PropertyId $property): array
    {
        return DB::table('spa_therapists')->where('property_id', $property->toString())->orderBy('name')->get()->map(static fn ($r): array => self::flags((array) $r))->all();
    }

    public function therapist(PropertyId $property, string $id): ?array
    {
        $r = DB::table('spa_therapists')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : self::flags((array) $r);
    }

    public function therapistOfUser(PropertyId $property, string $userId): ?array
    {
        $r = DB::table('spa_therapists')->where('property_id', $property->toString())->where('user_id', $userId)->where('is_active', true)->first();

        return $r === null ? null : self::flags((array) $r);
    }

    public function addTherapist(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('spa_therapists', $property, $row, $at);
    }

    public function updateTherapist(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return $this->update('spa_therapists', $property, $id, $lock, $fields, $at);
    }

    public function attendanceOn(PropertyId $property, string $date): array
    {
        $out = [];

        foreach (DB::table('spa_attendance')->where('property_id', $property->toString())->where('business_date', $date)->get() as $r) {
            $out[(string) $r->therapist_id] = ['clock_in_at' => self::utc($r->clock_in_at), 'clock_out_at' => $r->clock_out_at === null ? null : self::utc($r->clock_out_at)];
        }

        return $out;
    }

    public function clockIn(PropertyId $property, string $id, string $therapistId, string $date, DateTimeImmutable $at): bool
    {
        try {
            DB::table('spa_attendance')->insert(['id' => $id, 'property_id' => $property->toString(), 'therapist_id' => $therapistId, 'business_date' => $date, 'clock_in_at' => $at, 'created_at' => $at, 'updated_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function clockOut(PropertyId $property, string $therapistId, string $date, DateTimeImmutable $at): bool
    {
        return DB::table('spa_attendance')->where('property_id', $property->toString())->where('therapist_id', $therapistId)->where('business_date', $date)->whereNull('clock_out_at')
            ->update(['clock_out_at' => $at, 'updated_at' => $at]) === 1;
    }

    public function addBooking(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('spa_bookings')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function booking(PropertyId $property, string $id): ?array
    {
        $r = DB::table('spa_bookings')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : self::booking_((array) $r);
    }

    public function updateBooking(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return $this->update('spa_bookings', $property, $id, $lock, $fields, $at);
    }

    public function lockBooking(PropertyId $property, string $id): void
    {
        DB::table('spa_bookings')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function lockRoomAndTherapist(PropertyId $property, string $roomId, string $therapistId): void
    {
        DB::table('spa_rooms')->where('property_id', $property->toString())->where('id', $roomId)->lockForUpdate()->first();
        DB::table('spa_therapists')->where('property_id', $property->toString())->where('id', $therapistId)->lockForUpdate()->first();
    }

    public function bookingsOn(PropertyId $property, string $date): array
    {
        return DB::table('spa_bookings')->where('property_id', $property->toString())->where('booking_date', $date)->orderBy('start_minute')->orderBy('number')->get()->map(static fn ($r): array => self::booking_((array) $r))->all();
    }

    public function bookingsOfTherapistOn(PropertyId $property, string $therapistId, string $date): array
    {
        return DB::table('spa_bookings')->where('property_id', $property->toString())->where('therapist_id', $therapistId)->where('booking_date', $date)->orderBy('start_minute')->get()->map(static fn ($r): array => self::booking_((array) $r))->all();
    }

    public function holding(PropertyId $property, string $column, string $id, string $date, ?string $exceptBookingId): array
    {
        if (! in_array($column, ['room_id', 'therapist_id'], true)) {
            return [];
        }

        return DB::table('spa_bookings')->where('property_id', $property->toString())->where($column, $id)->where('booking_date', $date)->whereIn('status', self::HOLDING)
            ->when($exceptBookingId !== null, static fn ($q) => $q->where('id', '<>', $exceptBookingId))
            ->get(['start_minute', 'minutes'])->map(static fn ($r): array => ['start' => (int) $r->start_minute, 'end' => (int) $r->start_minute + (int) $r->minutes])->all();
    }

    public function doneBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('spa_bookings')->where('property_id', $property->toString())->where('status', 'done')->whereBetween('booking_date', [$from, $to])->orderBy('booking_date')->orderBy('start_minute')->get()
            ->map(static fn ($r): array => self::booking_((array) $r))->all();
    }

    public function openShiftOf(PropertyId $property, string $cashierId): ?string
    {
        $id = DB::table('cashier_shifts')->where('property_id', $property->toString())->where('cashier_id', $cashierId)->where('status', 'open')->sharedLock()->value('id');

        return is_string($id) ? $id : null;
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table($table)->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $fields */
    private function update(string $table, PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        try {
            return DB::table($table)->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function flags(array $row): array
    {
        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = (bool) $row['is_active'];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function booking_(array $row): array
    {
        $row['waive_service_charge'] = (bool) $row['waive_service_charge'];
        $row['waive_tax'] = (bool) $row['waive_tax'];
        $row['booking_date'] = substr((string) $row['booking_date'], 0, 10);

        foreach (['started_at', 'ended_at'] as $key) {
            $row[$key] = $row[$key] === null ? null : self::utc($row[$key]);
        }

        return $row;
    }

    private static function utc(mixed $value): string
    {
        return (new DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
