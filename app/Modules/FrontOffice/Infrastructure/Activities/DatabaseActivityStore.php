<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Activities;

use App\Modules\FrontOffice\Application\Activities\ActivityCapacity;
use App\Modules\FrontOffice\Application\Activities\ActivityStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseActivityStore implements ActivityStore
{
    public function services(PropertyId $property): array
    {
        return DB::table('activity_services')->where('property_id', $property->toString())->orderByDesc('is_active')->orderBy('name')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function service(PropertyId $property, string $id): ?array
    {
        $row = DB::table('activity_services')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function lockService(PropertyId $property, string $id): ?array
    {
        $row = DB::table('activity_services')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();

        return $row === null ? null : (array) $row;
    }

    public function addService(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('activity_services')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function updateService(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('activity_services')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addBooking(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('activity_bookings')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'booked', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function booking(PropertyId $property, string $id): ?array
    {
        $row = DB::table('activity_bookings')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function updateBooking(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('activity_bookings')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function holdingPlaces(PropertyId $property, string $serviceId, string $day): array
    {
        $out = [];

        foreach (DB::table('activity_bookings')->where('property_id', $property->toString())->where('service_id', $serviceId)->where('starts_on', $day)->whereIn('status', ['booked', 'done'])->get(['starts_at', 'duration_minutes', 'guests']) as $r) {
            $start = (int) ActivityCapacity::minutes((string) $r->starts_at);
            $out[] = ['start' => $start, 'end' => $start + (int) $r->duration_minutes, 'guests' => (int) $r->guests];
        }

        return $out;
    }

    public function bookings(PropertyId $property, string $since, int $limit): array
    {
        return DB::table('activity_bookings')->where('property_id', $property->toString())->where(fn ($q) => $q->where('starts_on', '>=', $since)->orWhere('status', 'booked'))->orderBy('starts_on')->orderBy('starts_at')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }
}
