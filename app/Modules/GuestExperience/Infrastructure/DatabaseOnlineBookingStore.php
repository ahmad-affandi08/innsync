<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\OnlineBookingStore;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;
use Illuminate\Support\Facades\DB;

final class DatabaseOnlineBookingStore implements OnlineBookingStore
{
    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('online_booking_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : [
            'enabled' => (bool) $r->enabled, 'rate_plan_id' => $r->rate_plan_id === null ? null : (string) $r->rate_plan_id, 'max_nights' => (int) $r->max_nights,
            'notify_email' => $r->notify_email === null ? null : (string) $r->notify_email, 'notice' => $r->notice === null ? null : (string) $r->notice, 'actor_user_id' => $r->actor_user_id === null ? null : (string) $r->actor_user_id, 'remind_before_arrival' => (bool) $r->remind_before_arrival, 'thank_after_stay' => (bool) $r->thank_after_stay, 'updated_at' => (string) $r->updated_at,
        ];
    }

    public function save(PropertyId $property, bool $enabled, ?string $ratePlanId, int $maxNights, ?string $notifyEmail, ?string $notice, ?string $actorUserId, string $actorId): void
    {
        $now = now();
        $values = ['enabled' => $enabled, 'rate_plan_id' => $ratePlanId, 'max_nights' => $maxNights, 'notify_email' => $notifyEmail, 'notice' => $notice, 'updated_by' => $actorId, 'updated_at' => $now];
        $values = $actorUserId === null ? $values : [...$values, 'actor_user_id' => $actorUserId];

        if (DB::table('online_booking_settings')->where('property_id', $property->toString())->exists()) {
            DB::table('online_booking_settings')->where('property_id', $property->toString())->update($values);

            return;
        }

        DB::table('online_booking_settings')->insert(['property_id' => $property->toString(), ...$values, 'created_at' => $now]);
    }

    public function bookable(string $propertyId): ?PropertyId
    {
        if (preg_match('/^[0-9a-z]{26}$/', $propertyId) !== 1) {
            return null;
        }

        $on = DB::table('online_booking_settings as s')->join('properties as p', 'p.id', '=', 's.property_id')
            ->where('s.property_id', $propertyId)->where('s.enabled', true)->whereNotNull('s.rate_plan_id')->where('p.is_active', true)->exists();

        return $on ? PropertyId::fromString($propertyId) : null;
    }

    public function saveMessages(PropertyId $property, bool $remindBeforeArrival, bool $thankAfterStay): void
    {
        DB::table('online_booking_settings')->where('property_id', $property->toString())->update(['remind_before_arrival' => $remindBeforeArrival, 'thank_after_stay' => $thankAfterStay, 'updated_at' => now()]);
    }

    public function propertiesWithMessages(): array
    {
        return DB::table('online_booking_settings')->where(static fn ($q) => $q->where('remind_before_arrival', true)->orWhere('thank_after_stay', true))->pluck('property_id')->map(static fn ($id): string => (string) $id)->all();
    }

    public function messageCandidates(PropertyId $property, string $kind, string $day): array
    {
        $query = DB::table('reservations as r')->where('r.property_id', $property->toString())->whereNotNull('r.guest_email')->where('r.guest_email', '<>', '')
            ->whereNotExists(static fn ($q) => $q->from('guest_message_log as g')->whereColumn('g.reservation_id', 'r.id')->where('g.kind', $kind));

        if ($kind === 'pre_arrival') {
            $query->where('r.arrival_date', $day)->whereIn('r.status', ['tentative', 'confirmed', 'guaranteed']);
        } else {
            $query->where('r.status', 'completed')->where('r.departure_date', '<=', $day)->where('r.departure_date', '>=', DateMath::addDays($day, -1));
        }

        return $query->orderBy('r.arrival_date')->limit(200)->get(['r.id', 'r.number', 'r.guest_name', 'r.guest_email', 'r.arrival_date', 'r.departure_date'])
            ->map(static fn (object $r): array => ['id' => (string) $r->id, 'number' => (string) $r->number, 'guest_name' => (string) $r->guest_name, 'guest_email' => (string) $r->guest_email, 'arrival' => (string) $r->arrival_date, 'departure' => (string) $r->departure_date])->all();
    }

    public function logSent(PropertyId $property, string $reservationId, string $kind): bool
    {
        return DB::table('guest_message_log')->insertOrIgnore(['property_id' => $property->toString(), 'reservation_id' => $reservationId, 'kind' => $kind, 'sent_at' => now()]) > 0;
    }
}
