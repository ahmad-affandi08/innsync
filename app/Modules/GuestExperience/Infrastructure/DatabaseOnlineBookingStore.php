<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\OnlineBookingStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseOnlineBookingStore implements OnlineBookingStore
{
    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('online_booking_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : [
            'enabled' => (bool) $r->enabled, 'rate_plan_id' => $r->rate_plan_id === null ? null : (string) $r->rate_plan_id, 'max_nights' => (int) $r->max_nights,
            'notify_email' => $r->notify_email === null ? null : (string) $r->notify_email, 'notice' => $r->notice === null ? null : (string) $r->notice, 'actor_user_id' => $r->actor_user_id === null ? null : (string) $r->actor_user_id, 'updated_at' => (string) $r->updated_at,
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
}
