<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Reminders;

use App\Modules\FrontOffice\Application\Reminders\AutoReminderSource;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseAutoReminderSource implements AutoReminderSource
{
    public function tentativeArrivals(PropertyId $property, string $untilDay): array
    {
        return DB::table('reservations')->where('property_id', $property->toString())->where('status', 'tentative')->where('arrival_date', '<=', $untilDay)->orderBy('arrival_date')->limit(200)
            ->get(['id', 'number', 'guest_name', 'arrival_date'])
            ->map(static fn (object $r): array => ['id' => (string) $r->id, 'number' => (string) $r->number, 'guest_name' => (string) $r->guest_name, 'arrival' => (string) $r->arrival_date])->all();
    }

    public function lapsingHolds(PropertyId $property, string $untilDay): array
    {
        return DB::table('inventory_holds')->where('property_id', $property->toString())->whereNull('released_at')->whereNotNull('expires_at')->whereRaw('DATE(expires_at) <= ?', [$untilDay])->orderBy('expires_at')->limit(200)
            ->get(['id', 'start_date', 'end_date', 'rooms', 'expires_at'])
            ->map(static fn (object $h): array => ['id' => (string) $h->id, 'start' => (string) $h->start_date, 'end' => (string) $h->end_date, 'rooms' => (int) $h->rooms, 'expires_on' => substr((string) $h->expires_at, 0, 10)])->all();
    }

    public function addOnce(PropertyId $property, string $id, string $autoKey, string $dueOn, string $text, ?string $reservationId): bool
    {
        return DB::table('fo_reminders')->insertOrIgnore(['id' => $id, 'property_id' => $property->toString(), 'due_on' => $dueOn, 'due_time' => null, 'text' => mb_substr($text, 0, 300), 'reservation_id' => $reservationId, 'room_id' => null, 'status' => 'open', 'auto_key' => $autoKey, 'created_by' => null, 'created_at' => now(), 'updated_at' => now()]) > 0;
    }

    public function propertyIds(): array
    {
        return DB::table('properties')->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    }
}
