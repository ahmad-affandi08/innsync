<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestNotes;

use App\Shared\Domain\Tenancy\PropertyId;

interface GuestNoteStore
{
    /** @return array{name: string, phone: string|null}|null who the reservation is for */
    public function guestOf(PropertyId $property, string $reservationId): ?array;

    /** @return array{flag: string|null, note: string|null, updated_at: string}|null */
    public function find(PropertyId $property, string $guestKey): ?array;

    public function save(PropertyId $property, string $guestKey, ?string $flag, ?string $note, string $actorId): void;
}
