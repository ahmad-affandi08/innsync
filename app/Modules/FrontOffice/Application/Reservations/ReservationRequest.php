<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

/** Input of a new reservation as the screen or an integration sends it. Everything is validated by `ReservationService`. */
final readonly class ReservationRequest
{
    public function __construct(
        public string $source,
        public string $guestName,
        public ?string $guestPhone,
        public ?string $guestEmail,
        public string $arrival,
        public string $departure,
        public int $adults,
        public int $children,
        public string $roomTypeId,
        public string $ratePlanId,
        public ?string $notes,
        /** `tentative` or `confirmed`. A guaranteed booking needs a deposit and comes with the payment tasks. */
        public string $status = 'tentative',
        /** The person saw the oversell warning and accepts it (needs the override permission and a reason). */
        public bool $acknowledgeOversell = false,
        public ?string $oversellReason = null,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprint(): array
    {
        return [
            'source' => $this->source, 'guest_name' => $this->guestName, 'phone' => $this->guestPhone, 'email' => $this->guestEmail,
            'arrival' => $this->arrival, 'departure' => $this->departure, 'adults' => $this->adults, 'children' => $this->children,
            'room_type_id' => strtolower($this->roomTypeId), 'rate_plan_id' => strtolower($this->ratePlanId), 'notes' => $this->notes,
            'status' => $this->status, 'ack' => $this->acknowledgeOversell, 'oversell_reason' => $this->oversellReason,
        ];
    }
}
