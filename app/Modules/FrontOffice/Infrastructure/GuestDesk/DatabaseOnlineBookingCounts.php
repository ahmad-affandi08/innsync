<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\GuestDesk;

use App\Modules\FrontOffice\Application\GuestDesk\OnlineBookingCounts;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseOnlineBookingCounts implements OnlineBookingCounts
{
    public function tentativeBy(PropertyId $property, string $actorId): int
    {
        return DB::table('reservations')->where('property_id', $property->toString())->where('created_by', $actorId)->where('status', 'tentative')->count();
    }

    public function recentTentative(PropertyId $property, string $actorId, ?string $email, ?string $phone, int $hours): int
    {
        if (($email === null || $email === '') && ($phone === null || $phone === '')) {
            return 0;
        }

        return DB::table('reservations')->where('property_id', $property->toString())->where('created_by', $actorId)->where('status', 'tentative')
            ->where('created_at', '>=', now()->subHours($hours))
            ->where(static function ($q) use ($email, $phone): void {
                if ($email !== null && $email !== '') {
                    $q->orWhere('guest_email', $email);
                }

                if ($phone !== null && $phone !== '') {
                    $q->orWhere('guest_phone', $phone);
                }
            })->count();
    }
}
