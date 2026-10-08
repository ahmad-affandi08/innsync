<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\RoomPlan;

use App\Modules\FrontOffice\Application\RoomPlan\RoomPlanStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseRoomPlanStore implements RoomPlanStore
{
    private const LIVE = ['tentative', 'confirmed', 'guaranteed', 'checked_in'];

    public function reservation(PropertyId $property, string $reservationId): ?array
    {
        $r = DB::table('reservations')->where('property_id', $property->toString())->where('id', $reservationId)->first(['id', 'status', 'room_type_id', 'arrival_date', 'departure_date']);

        return $r === null ? null : ['id' => (string) $r->id, 'status' => (string) $r->status, 'room_type_id' => (string) $r->room_type_id, 'arrival' => (string) $r->arrival_date, 'departure' => (string) $r->departure_date];
    }

    public function plannedRoom(PropertyId $property, string $reservationId): ?string
    {
        $room = DB::table('room_plans')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->value('room_id');

        return $room === null ? null : (string) $room;
    }

    public function conflict(PropertyId $property, string $roomId, string $arrival, string $departure, string $exceptReservationId): ?string
    {
        $p = $property->toString();

        $planned = DB::table('room_plans as rp')->join('reservations as v', 'v.id', '=', 'rp.reservation_id')
            ->where('rp.property_id', $p)->where('rp.room_id', $roomId)->where('rp.reservation_id', '<>', $exceptReservationId)
            ->whereIn('v.status', self::LIVE)->where('v.arrival_date', '<', $departure)->where('v.departure_date', '>', $arrival)->exists();

        if ($planned) {
            return 'This room is already planned for another booking on these nights.';
        }

        $held = DB::table('reservations')->where('property_id', $p)->where('room_id', $roomId)->where('id', '<>', $exceptReservationId)
            ->whereIn('status', self::LIVE)->where('arrival_date', '<', $departure)->where('departure_date', '>', $arrival)->exists();

        if ($held) {
            return 'A guest holds this room on these nights.';
        }

        $blocked = DB::table('room_blocks')->where('property_id', $p)->where('room_id', $roomId)->whereNull('released_at')
            ->where('start_date', '<', $departure)->where('end_date', '>=', $arrival)->exists();

        return $blocked ? 'This room is out of sale on these nights.' : null;
    }

    public function set(PropertyId $property, string $reservationId, string $roomId, string $actorId): void
    {
        $now = now();
        $where = ['reservation_id' => $reservationId];

        if (DB::table('room_plans')->where($where)->exists()) {
            DB::table('room_plans')->where($where)->update(['room_id' => $roomId, 'planned_by' => $actorId, 'updated_at' => $now]);

            return;
        }

        DB::table('room_plans')->insert([...$where, 'property_id' => $property->toString(), 'room_id' => $roomId, 'planned_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function clear(PropertyId $property, string $reservationId): bool
    {
        return DB::table('room_plans')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->delete() > 0;
    }
}
