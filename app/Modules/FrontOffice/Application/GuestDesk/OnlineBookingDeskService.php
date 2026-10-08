<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDesk;

use App\Modules\FrontOffice\Application\BookingRefused;
use App\Modules\FrontOffice\Application\Inventory\AvailabilityService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\RatePlanReader;
use App\Modules\Property\Application\Rates\RateQuoter;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;
use InvalidArgumentException;

final readonly class OnlineBookingDeskService implements OnlineBookingDesk
{
    public function __construct(
        private ReservationService $reservations,
        private RateQuoter $quoter,
        private AvailabilityService $availability,
        private RoomCatalogReader $rooms,
        private RatePlanReader $plans,
        private BusinessDateProvider $businessDate,
        private PropertySettingsService $settings,
        private OnlineBookingCounts $counts,
    ) {}

    public function today(PropertyId $property): string
    {
        return $this->businessDate->current($property)->toString();
    }

    public function horizonDays(PropertyId $property): int
    {
        return $this->settings->get($property)->availabilityHorizonDays;
    }

    public function offers(PropertyId $property, string $ratePlanId, string $arrival, string $departure, int $adults, int $children): array
    {
        try {
            $stay = new StayDates(BusinessDate::fromString($arrival), BusinessDate::fromString($departure));
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['arrival', 'departure']);
        }

        $out = [];

        foreach ($this->rooms->activeTypes($property) as $type) {
            if ($adults > $type->maxAdults || $children > $type->maxChildren) {
                continue;
            }

            $quote = $this->quoter->describe($property, $ratePlanId, $type->id, $arrival, $departure);
            $reason = null;

            if (! $quote['bookable']) {
                $reason = 'not_bookable';
            } else {
                foreach ($this->availability->forStay($property, $type->id, $stay, false) as $availability) {
                    if (! $availability->canSellOne() || $availability->needsOverbooking()) {
                        $reason = 'sold_out';

                        break;
                    }
                }
            }

            $out[] = [
                'room_type_id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'max_adults' => $type->maxAdults, 'max_children' => $type->maxChildren,
                'available' => $reason === null, 'reason' => $reason, 'currency' => (string) $quote['currency'], 'total_minor' => (int) $quote['total_minor'],
                'nights' => array_map(static fn (array $n): array => ['date' => (string) $n['date'], 'total_minor' => (int) $n['total_minor']], $quote['nights']),
            ];
        }

        return $out;
    }

    public function book(PropertyId $property, string $actorId, array $guest, string $arrival, string $departure, int $adults, int $children, string $roomTypeId, string $ratePlanId, ?string $notes, string $key): array
    {
        try {
            $reservation = $this->reservations->create(
                $property,
                $actorId,
                new ReservationRequest('direct', $guest['name'], $guest['phone'], $guest['email'], $arrival, $departure, $adults, $children, $roomTypeId, $ratePlanId, $notes, 'tentative'),
                IdempotencyKey::fromString($key),
            );
        } catch (BookingRefused $e) {
            // The hotel's own wording of why it cannot take this booking is meant for staff; the web page gets the reason as a code.
            throw Refusal::stateConflict('unavailable');
        }

        $quote = $this->quoter->describe($property, $ratePlanId, $roomTypeId, $arrival, $departure);

        return ['reservation_id' => $reservation->id, 'number' => $reservation->number, 'status' => $reservation->status->value, 'total_minor' => $reservation->total->amountMinor, 'currency' => (string) $quote['currency']];
    }

    public function awaiting(PropertyId $property, string $actorId): int
    {
        return $this->counts->tentativeBy($property, $actorId);
    }

    public function recentForContact(PropertyId $property, string $actorId, ?string $email, ?string $phone, int $hours): int
    {
        return $this->counts->recentTentative($property, $actorId, $email, $phone, $hours);
    }

    public function ratePlans(PropertyId $property): array
    {
        return array_map(static fn ($p): array => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name], $this->plans->activePlans($property));
    }
}
