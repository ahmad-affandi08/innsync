<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDesk;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the guest self check-in needs from front office (FR-GST-001 to FR-GST-007): the reservation behind a link, and, when a receptionist verifies what the guest sent, the check-in itself. Reading an
 * arrival is not a staff action (the caller holds a link or has checked the name); everything that changes the stay, the room or the folio is done as the receptionist who verifies, with the checks,
 * permissions and audit of the front desk, so a guest never changes a room by sending a form.
 */
interface SelfCheckInDesk
{
    /**
     * @return array{reservation_id: string, number: string, status: string, guest_name: string, arrival: string, departure: string, nights: int, adults: int, children: int, max_adults: int|null, max_children: int|null, room_type: string|null, currency: string, deposit_required_minor: int, deposit_held_minor: int, has_stay: bool}|null
     */
    public function arrival(PropertyId $property, string $reservationId): ?array;

    /** @return array{reservation_id: string, number: string, status: string, guest_name: string, arrival: string, departure: string, nights: int, adults: int, children: int, max_adults: int|null, max_children: int|null, room_type: string|null, currency: string, deposit_required_minor: int, deposit_held_minor: int, has_stay: bool}|null */
    public function arrivalByNumber(PropertyId $property, string $number): ?array;

    /**
     * Guests due to arrive from `$from` to `$to` (dates), for the receptionist to send links.
     *
     * @return list<array{reservation_id: string, number: string, status: string, guest_name: string, arrival: string, departure: string, nights: int, adults: int, children: int, room_type: string|null, has_stay: bool}>
     */
    public function arrivals(PropertyId $property, string $from, string $to): array;

    /** @return list<array{id: string, number: string, floor: string|null, ready: bool}> the rooms the receptionist may give this guest */
    public function rooms(PropertyId $property, string $actorId, string $reservationId): array;

    /**
     * Checks the guest in, as the receptionist: the stay, the identity photo and the signature on the registration card.
     *
     * @param  array{full_name: string, nationality: string, id_type: string, id_number: string, id_valid_until: string|null, visa_number: string|null, address: string, adults: int, children: int}  $guest
     * @return array{stay_id: string, room_number: string|null, warnings: list<string>}
     */
    public function checkIn(PropertyId $property, string $actorId, string $reservationId, string $roomId, array $guest, ?string $photo, ?string $photoName, ?string $signaturePng, string $key): array;

    /** Posts the deposit the guest paid by QRIS to the folio of the reservation, once for a reference of the source, as the receptionist who has seen the money. */
    public function receiveDeposit(PropertyId $property, string $actorId, string $reservationId, int $amountMinor, ?string $reference, string $sourceRef): void;
}
