<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Reminders;

use App\Modules\FrontOffice\Application\Reminders\ReminderStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseReminderStore implements ReminderStore
{
    public function list(PropertyId $property, string $status, int $limit): array
    {
        $query = DB::table('fo_reminders as r')
            ->leftJoin('reservations as v', 'v.id', '=', 'r.reservation_id')
            ->leftJoin('rooms as m', 'm.id', '=', 'r.room_id')
            ->leftJoin('users as c', 'c.id', '=', 'r.created_by')
            ->leftJoin('users as d', 'd.id', '=', 'r.done_by')
            ->where('r.property_id', $property->toString())
            ->where('r.status', $status);

        $status === 'open' ? $query->orderBy('r.due_on')->orderByRaw('r.due_time IS NULL')->orderBy('r.due_time') : $query->orderByDesc('r.done_at');

        return $query->limit($limit)->get(['r.id', 'r.due_on', 'r.due_time', 'r.text', 'r.status', 'r.reservation_id', 'v.number as reservation_number', 'v.guest_name', 'm.number as room_number', 'c.name as created_by_name', 'd.name as done_by_name', 'r.done_at'])
            ->map(static fn (object $x): array => [
                'id' => (string) $x->id, 'due_on' => (string) $x->due_on, 'due_time' => $x->due_time === null ? null : (string) $x->due_time, 'text' => (string) $x->text, 'status' => (string) $x->status,
                'reservation_id' => $x->reservation_id === null ? null : (string) $x->reservation_id, 'reservation_number' => $x->reservation_number === null ? null : (string) $x->reservation_number,
                'guest_name' => $x->guest_name === null ? null : (string) $x->guest_name, 'room_number' => $x->room_number === null ? null : (string) $x->room_number,
                'created_by_name' => $x->created_by_name === null ? null : (string) $x->created_by_name, 'done_by_name' => $x->done_by_name === null ? null : (string) $x->done_by_name, 'done_at' => $x->done_at === null ? null : (string) $x->done_at,
            ])->all();
    }

    public function add(PropertyId $property, string $id, string $dueOn, ?string $dueTime, string $text, ?string $reservationId, ?string $roomId, string $actorId): void
    {
        DB::table('fo_reminders')->insert(['id' => $id, 'property_id' => $property->toString(), 'due_on' => $dueOn, 'due_time' => $dueTime, 'text' => $text, 'reservation_id' => $reservationId, 'room_id' => $roomId, 'status' => 'open', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function markDone(PropertyId $property, string $id, string $actorId): bool
    {
        return DB::table('fo_reminders')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->update(['status' => 'done', 'done_by' => $actorId, 'done_at' => now(), 'updated_at' => now()]) > 0;
    }

    public function reopen(PropertyId $property, string $id): bool
    {
        return DB::table('fo_reminders')->where('property_id', $property->toString())->where('id', $id)->where('status', 'done')->update(['status' => 'open', 'done_by' => null, 'done_at' => null, 'updated_at' => now()]) > 0;
    }

    public function dueCount(PropertyId $property, string $day): int
    {
        return DB::table('fo_reminders')->where('property_id', $property->toString())->where('status', 'open')->where('due_on', '<=', $day)->count();
    }

    public function reservationExists(PropertyId $property, string $reservationId): bool
    {
        return DB::table('reservations')->where('property_id', $property->toString())->where('id', $reservationId)->exists();
    }

    public function roomExists(PropertyId $property, string $roomId): bool
    {
        return DB::table('rooms')->where('property_id', $property->toString())->where('id', $roomId)->exists();
    }
}
