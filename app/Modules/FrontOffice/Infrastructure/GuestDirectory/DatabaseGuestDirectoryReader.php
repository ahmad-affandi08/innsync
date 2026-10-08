<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\GuestDirectory;

use App\Modules\FrontOffice\Application\GuestDirectory\GuestDirectoryReader;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseGuestDirectoryReader implements GuestDirectoryReader
{
    public function guests(PropertyId $property, string $query, int $limit): array
    {
        $rows = DB::table('reservations')
            ->where('property_id', $property->toString())
            ->whereIn('status', ['confirmed', 'guaranteed', 'checked_in', 'completed'])
            ->when($query !== '', static fn ($q) => $q->where('guest_name', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderByDesc('arrival_date')
            ->limit(5000)
            ->get(['id', 'guest_name', 'guest_phone', 'status', 'arrival_date', 'departure_date']);

        $guests = [];

        foreach ($rows as $r) {
            // The same name with the same phone is one guest; a name alone is not enough to say two bookings are the same person.
            $key = mb_strtolower(trim((string) $r->guest_name)).'|'.preg_replace('/\D/', '', (string) $r->guest_phone);
            $nights = max(0, (int) ((strtotime((string) $r->departure_date) - strtotime((string) $r->arrival_date)) / 86400));
            $done = in_array($r->status, ['checked_in', 'completed'], true);

            if (! isset($guests[$key])) {
                $guests[$key] = ['guest_name' => (string) $r->guest_name, 'stays' => 0, 'nights' => 0, 'last_arrival' => (string) $r->arrival_date, 'last_departure' => (string) $r->departure_date, 'last_reservation_id' => (string) $r->id, 'upcoming' => 0];
            }

            if ($done) {
                $guests[$key]['stays']++;
                $guests[$key]['nights'] += $nights;
            } else {
                $guests[$key]['upcoming']++;
            }
        }

        return array_slice(array_values($guests), 0, $limit);
    }
}
