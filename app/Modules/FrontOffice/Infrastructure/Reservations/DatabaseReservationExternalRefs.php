<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Reservations;

use App\Modules\FrontOffice\Application\Reservations\ReservationExternalRefs;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseReservationExternalRefs implements ReservationExternalRefs
{
    public function reservationOf(PropertyId $property, string $channel, string $externalRef): ?string
    {
        $id = DB::table('reservation_external_refs')->where('property_id', $property->toString())->where('channel', $channel)->where('external_ref', $externalRef)->value('reservation_id');

        return $id === null ? null : (string) $id;
    }

    public function add(PropertyId $property, string $channel, string $externalRef, string $reservationId, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('reservation_external_refs')->insert([
                'id' => strtolower((string) Str::ulid()), 'property_id' => $property->toString(), 'channel' => $channel, 'external_ref' => $externalRef,
                'reservation_id' => $reservationId, 'imported_by' => $actorId, 'created_at' => $at,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
