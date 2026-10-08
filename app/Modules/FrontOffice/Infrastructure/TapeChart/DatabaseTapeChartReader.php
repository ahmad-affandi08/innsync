<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\TapeChart;

use App\Modules\FrontOffice\Application\TapeChart\TapeChartReader;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseTapeChartReader implements TapeChartReader
{
    public function reservations(PropertyId $property, string $from, string $to): array
    {
        return DB::table('reservations as v')
            ->leftJoin('room_plans as rp', 'rp.reservation_id', '=', 'v.id')
            ->where('v.property_id', $property->toString())
            ->whereNotIn('v.status', ['cancelled', 'no_show'])
            ->where('v.arrival_date', '<', $to)
            ->where('v.departure_date', '>', $from)
            ->orderBy('v.arrival_date')
            ->limit(2000)
            ->get(['v.id', 'v.number', 'v.status', 'v.guest_name', 'v.room_id', 'rp.room_id as planned_room_id', 'v.room_type_id', 'v.arrival_date', 'v.departure_date'])
            ->map(static fn (object $r): array => ['id' => (string) $r->id, 'number' => (string) $r->number, 'status' => (string) $r->status, 'guest_name' => (string) $r->guest_name, 'room_id' => $r->room_id === null ? null : (string) $r->room_id, 'planned_room_id' => $r->planned_room_id === null ? null : (string) $r->planned_room_id, 'room_type_id' => (string) $r->room_type_id, 'arrival' => (string) $r->arrival_date, 'departure' => (string) $r->departure_date])
            ->all();
    }

    public function blocks(PropertyId $property, string $from, string $to): array
    {
        return DB::table('room_blocks')
            ->where('property_id', $property->toString())
            ->whereNull('released_at')
            ->where('start_date', '<', $to)
            ->where('end_date', '>=', $from)
            ->limit(2000)
            ->get(['room_id', 'kind', 'start_date', 'end_date', 'reason'])
            ->map(static fn (object $b): array => ['room_id' => (string) $b->room_id, 'kind' => (string) $b->kind, 'start' => (string) $b->start_date, 'end' => (string) $b->end_date, 'reason' => (string) $b->reason])
            ->all();
    }
}
