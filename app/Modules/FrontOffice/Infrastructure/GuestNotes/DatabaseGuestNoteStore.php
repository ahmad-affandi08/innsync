<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\GuestNotes;

use App\Modules\FrontOffice\Application\GuestNotes\GuestNoteStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseGuestNoteStore implements GuestNoteStore
{
    public function guestOf(PropertyId $property, string $reservationId): ?array
    {
        $row = DB::table('reservations')->where('property_id', $property->toString())->where('id', $reservationId)->first(['guest_name', 'guest_phone']);

        return $row === null ? null : ['name' => (string) $row->guest_name, 'phone' => $row->guest_phone === null ? null : (string) $row->guest_phone];
    }

    public function find(PropertyId $property, string $guestKey): ?array
    {
        $row = DB::table('guest_notes')->where('property_id', $property->toString())->where('guest_key', $guestKey)->first(['flag', 'note', 'updated_at']);

        return $row === null ? null : ['flag' => $row->flag === null ? null : (string) $row->flag, 'note' => $row->note === null ? null : (string) $row->note, 'updated_at' => (string) $row->updated_at];
    }

    public function save(PropertyId $property, string $guestKey, ?string $flag, ?string $note, string $actorId): void
    {
        $now = now();
        $where = ['property_id' => $property->toString(), 'guest_key' => $guestKey];

        if (DB::table('guest_notes')->where($where)->exists()) {
            DB::table('guest_notes')->where($where)->update(['flag' => $flag, 'note' => $note, 'updated_by' => $actorId, 'updated_at' => $now]);

            return;
        }

        DB::table('guest_notes')->insert([...$where, 'flag' => $flag, 'note' => $note, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
    }
}
