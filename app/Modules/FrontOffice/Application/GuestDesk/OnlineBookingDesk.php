<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDesk;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What booking from the hotel's own web page needs from front office. Showing what can be sold and at what price is not a staff action; making the reservation is done as the
 * property's "Online booking" account, so it goes through every rule of the front desk (the dates, the horizon, the occupancy, the minimum stay, the availability) and is
 * recorded under that account. A web booking is always tentative: it never sells beyond the available rooms and never asks for money.
 */
interface OnlineBookingDesk
{
    /** @return string today by the business date */
    public function today(PropertyId $property): string;

    /** @return int the number of days ahead a reservation may be made */
    public function horizonDays(PropertyId $property): int;

    /**
     * Every room type that can hold the party, with whether it can be sold for every night and the price of the whole stay with the service charge and tax in it.
     *
     * @return list<array{room_type_id: string, code: string, name: string, max_adults: int, max_children: int, available: bool, reason: string|null, currency: string, total_minor: int, nights: list<array{date: string, total_minor: int}>, photos: list<string>}>
     */
    public function offers(PropertyId $property, string $ratePlanId, string $arrival, string $departure, int $adults, int $children): array;

    /**
     * @param  array{name: string, phone: string|null, email: string|null}  $guest
     * @return array{reservation_id: string, number: string, status: string, total_minor: int, currency: string}
     */
    public function book(PropertyId $property, string $actorId, array $guest, string $arrival, string $departure, int $adults, int $children, string $roomTypeId, string $ratePlanId, ?string $notes, string $key): array;

    /** Tentative reservations this account made that nobody has dealt with yet. */
    public function awaiting(PropertyId $property, string $actorId): int;

    /** How many tentative reservations this account made in the last hours for the same e-mail address or phone, to stop one person filling the calendar. */
    public function recentForContact(PropertyId $property, string $actorId, ?string $email, ?string $phone, int $hours): int;

    /** @return list<array{id: string, code: string, name: string}> the rate plans that can be offered */
    public function ratePlans(PropertyId $property): array;
}
