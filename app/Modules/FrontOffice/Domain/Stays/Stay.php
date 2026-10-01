<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Stays;

use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/** A guest in a room (FR-FO-010, FR-FO-016): from check-in to check-out. The facts of the check-in never change. */
final readonly class Stay
{
    public function __construct(
        public string $id,
        public string $reservationId,
        public string $guestId,
        public string $roomId,
        public StayStatus $status,
        public int $adults,
        public int $children,
        public BusinessDate $checkedInDate,
        public DateTimeImmutable $checkedInAt,
        public BusinessDate $expectedDeparture,
        public ?BusinessDate $checkedOutDate,
        public ?string $idPhotoFileId,
        public int $lockVersion,
    ) {}

    public function isInHouse(): bool
    {
        return $this->status === StayStatus::InHouse;
    }

    public function assertInHouse(): void
    {
        if (! $this->isInHouse()) {
            throw StayRuleViolation::alreadyOut();
        }
    }

    /** Checking out on the day of departure is normal; earlier is an early departure, later a late one. */
    public function departureKind(BusinessDate $today): string
    {
        return match (true) {
            $today->isBefore($this->expectedDeparture) => 'early',
            $today->isAfter($this->expectedDeparture) => 'late',
            default => 'on_time',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservationId,
            'guest_id' => $this->guestId,
            'room_id' => $this->roomId,
            'status' => $this->status->value,
            'adults' => $this->adults,
            'children' => $this->children,
            'checked_in_date' => $this->checkedInDate->toString(),
            'expected_departure' => $this->expectedDeparture->toString(),
            'checked_out_date' => $this->checkedOutDate?->toString(),
            'has_id_photo' => $this->idPhotoFileId !== null,
            'lock_version' => $this->lockVersion,
        ];
    }
}
