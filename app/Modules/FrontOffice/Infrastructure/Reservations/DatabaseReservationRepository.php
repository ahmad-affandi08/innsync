<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Reservations;

use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Reservations\BookingSource;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\FrontOffice\Domain\Reservations\ReservationStatus;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseReservationRepository implements ReservationRepository
{
    public function add(PropertyId $property, Reservation $r, string $currency, int $baseMinor, int $serviceChargeMinor, int $taxMinor, DateTimeImmutable $at): void
    {
        DB::table('reservations')->insert([
            'id' => $r->id, 'property_id' => $property->toString(), 'number' => $r->number, 'status' => $r->status->value, 'source' => $r->source->value,
            'guest_name' => $r->guestName, 'guest_phone' => $r->guestPhone, 'guest_email' => $r->guestEmail,
            'arrival_date' => $r->stay->arrival->toString(), 'departure_date' => $r->stay->departure->toString(), 'adults' => $r->adults, 'children' => $r->children,
            'room_type_id' => $r->roomTypeId, 'rate_plan_id' => $r->ratePlanId, 'room_id' => $r->roomId, 'notes' => $r->notes, 'currency_code' => $currency,
            'total_base_minor' => $baseMinor, 'total_service_charge_minor' => $serviceChargeMinor, 'total_tax_minor' => $taxMinor, 'total_minor' => $r->total->amountMinor,
            'price_snapshot' => json_encode($r->priceSnapshot, JSON_THROW_ON_ERROR), 'oversold' => $r->oversold, 'oversell_reason' => $r->oversellReason,
            'created_by' => $r->createdBy, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at,
        ]);

        DB::table('reservation_nights')->insert(array_map(static fn (BusinessDate $night): array => [
            'reservation_id' => $r->id, 'property_id' => $property->toString(), 'room_type_id' => $r->roomTypeId, 'night' => $night->toString(), 'is_active' => true,
        ], $r->stay->nights()));
    }

    public function find(PropertyId $property, string $id): ?Reservation
    {
        $row = DB::table('reservations')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function saveStatus(PropertyId $property, Reservation $r, int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool
    {
        $updated = DB::table('reservations')->where('property_id', $property->toString())->where('id', $r->id)->where('lock_version', $expectedLockVersion)->update([
            'status' => $r->status->value, 'room_id' => $r->roomId, 'status_reason' => $r->statusReason, 'status_changed_at' => $at, 'status_changed_by' => $actorId,
            'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at,
        ]);

        if ($updated !== 1) {
            return false;
        }

        DB::table('reservation_nights')->where('reservation_id', $r->id)->update(['is_active' => $r->status->holdsInventory()]);

        return true;
    }

    public function changeRoom(PropertyId $property, string $id, string $roomId, DateTimeImmutable $at): void
    {
        DB::table('reservations')->where('property_id', $property->toString())->where('id', $id)->update(['room_id' => $roomId, 'updated_at' => $at]);
    }

    public function shiftNights(PropertyId $property, string $id, BusinessDate $from, string $roomTypeId): int
    {
        return DB::table('reservation_nights')->where('property_id', $property->toString())->where('reservation_id', $id)->where('is_active', true)->where('night', '>=', $from->toString())
            ->update(['room_type_id' => $roomTypeId]);
    }

    public function addExtension(PropertyId $property, string $amendmentId, string $reservationId, BusinessDate $oldDeparture, BusinessDate $newDeparture, string $roomTypeId, array $nights, string $reason, BusinessDate $businessDate, string $actorId, DateTimeImmutable $at): void
    {
        $base = array_sum(array_column($nights, 'base_minor'));
        $serviceCharge = array_sum(array_column($nights, 'service_charge_minor'));
        $tax = array_sum(array_column($nights, 'tax_minor'));

        DB::table('reservation_amendments')->insert([
            'id' => $amendmentId, 'property_id' => $property->toString(), 'reservation_id' => $reservationId, 'kind' => 'extension', 'old_departure' => $oldDeparture->toString(),
            'new_departure' => $newDeparture->toString(), 'nights' => json_encode($nights, JSON_THROW_ON_ERROR), 'added_base_minor' => $base, 'added_service_charge_minor' => $serviceCharge,
            'added_tax_minor' => $tax, 'added_total_minor' => $base + $serviceCharge + $tax, 'business_date' => $businessDate->toString(), 'reason' => $reason, 'created_by' => $actorId, 'created_at' => $at,
        ]);
        DB::table('reservations')->where('property_id', $property->toString())->where('id', $reservationId)->update(['departure_date' => $newDeparture->toString(), 'updated_at' => $at]);

        DB::table('reservation_nights')->insert(array_map(static fn (array $n): array => [
            'reservation_id' => $reservationId, 'property_id' => $property->toString(), 'room_type_id' => $roomTypeId, 'night' => $n['date'], 'is_active' => true,
        ], $nights));
    }

    public function extensionNights(PropertyId $property, string $reservationId): array
    {
        $nights = [];

        foreach (DB::table('reservation_amendments')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->orderBy('created_at')->orderBy('id')->pluck('nights') as $json) {
            foreach (json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR) as $night) {
                $nights[] = $night;
            }
        }

        return $nights;
    }

    public function search(PropertyId $property, array $filters, int $limit, int $offset): array
    {
        $query = DB::table('reservations')->where('property_id', $property->toString());

        if (isset($filters['status']) && ReservationStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['arrival_from'])) {
            $query->where('arrival_date', '>=', $filters['arrival_from']);
        }

        if (isset($filters['arrival_to'])) {
            $query->where('arrival_date', '<=', $filters['arrival_to']);
        }

        if (isset($filters['query']) && trim($filters['query']) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['query'])).'%';
            $query->where(static fn ($q) => $q->where('number', 'like', $like)->orWhere('guest_name', 'like', $like));
        }

        return $query->orderBy('arrival_date')->orderBy('number')->limit($limit)->offset($offset)->get()
            ->map(static fn (stdClass $r): Reservation => self::hydrate($r))->all();
    }

    public function activeNights(PropertyId $property, string $id): array
    {
        return DB::table('reservation_nights')->where('property_id', $property->toString())->where('reservation_id', $id)->where('is_active', true)->orderBy('night')->pluck('night')
            ->map(static fn ($n): string => substr((string) $n, 0, 10))->all();
    }

    private static function hydrate(stdClass $r): Reservation
    {
        return new Reservation(
            $r->id, $r->number, ReservationStatus::from($r->status), BookingSource::from($r->source), $r->guest_name, $r->guest_phone, $r->guest_email,
            new StayDates(BusinessDate::fromString(substr((string) $r->arrival_date, 0, 10)), BusinessDate::fromString(substr((string) $r->departure_date, 0, 10))),
            (int) $r->adults, (int) $r->children, $r->room_type_id, $r->rate_plan_id, $r->room_id, $r->notes,
            Money::ofMinor((int) $r->total_minor, $r->currency_code), json_decode((string) $r->price_snapshot, true, 512, JSON_THROW_ON_ERROR),
            (bool) $r->oversold, $r->oversell_reason, $r->status_reason, $r->created_by, (int) $r->lock_version,
        );
    }
}
