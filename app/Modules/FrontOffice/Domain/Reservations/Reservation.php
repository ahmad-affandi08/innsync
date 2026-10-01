<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Reservations;

use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;

/**
 * A booking of one room type for a stay (FR-FO-003). It holds inventory for every night while it is tentative,
 * confirmed, guaranteed or checked in. The price is a snapshot taken when it was made and is never recomputed.
 * State changes go through named methods that enforce the PRD state machine.
 */
final readonly class Reservation
{
    /**
     * @param  array<string, mixed>  $priceSnapshot
     */
    public function __construct(
        public string $id,
        public string $number,
        public ReservationStatus $status,
        public BookingSource $source,
        public string $guestName,
        public ?string $guestPhone,
        public ?string $guestEmail,
        public StayDates $stay,
        public int $adults,
        public int $children,
        public string $roomTypeId,
        public string $ratePlanId,
        public ?string $roomId,
        public ?string $notes,
        public Money $total,
        public array $priceSnapshot,
        public bool $oversold,
        public ?string $oversellReason,
        public ?string $statusReason,
        public string $createdBy,
        public int $lockVersion,
    ) {
        if (trim($guestName) === '' || mb_strlen($guestName) > 150) {
            throw ReservationRuleViolation::invalidGuest('A guest name of at most 150 characters is required.');
        }

        if ($guestPhone !== null && preg_match('/^\+?[0-9 ()-]{5,30}$/D', $guestPhone) !== 1) {
            throw ReservationRuleViolation::invalidGuest('A phone number has 5 to 30 digits, spaces, hyphens or parentheses.');
        }

        if ($guestEmail !== null && (mb_strlen($guestEmail) > 190 || filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw ReservationRuleViolation::invalidGuest('The e-mail address is not valid.');
        }

        if ($adults < 1 || $adults > 40 || $children < 0 || $children > 40) {
            throw ReservationRuleViolation::invalidGuest('A reservation has 1 to 40 adults and 0 to 40 children.');
        }

        if ($notes !== null && mb_strlen($notes) > 1000) {
            throw ReservationRuleViolation::invalidGuest('Notes are at most 1000 characters.');
        }
    }

    public function confirm(): self
    {
        if ($this->status !== ReservationStatus::Tentative) {
            throw ReservationRuleViolation::notExpected($this->status);
        }

        return $this->with(ReservationStatus::Confirmed, null);
    }

    public function cancel(string $reason): self
    {
        $this->assertExpected();

        return $this->with(ReservationStatus::Cancelled, self::reason($reason));
    }

    /** Only on or after the arrival date, by the business date (BR-001). */
    public function noShow(string $reason, BusinessDate $businessDate): self
    {
        $this->assertExpected();

        if ($businessDate->isBefore($this->stay->arrival)) {
            throw ReservationRuleViolation::tooEarlyForNoShow();
        }

        return $this->with(ReservationStatus::NoShow, self::reason($reason));
    }

    private function assertExpected(): void
    {
        if (! $this->status->isExpected()) {
            throw ReservationRuleViolation::notExpected($this->status);
        }
    }

    private static function reason(string $reason): string
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw ReservationRuleViolation::reasonRequired();
        }

        return trim($reason);
    }

    private function with(ReservationStatus $status, ?string $reason): self
    {
        return new self(
            $this->id, $this->number, $status, $this->source, $this->guestName, $this->guestPhone, $this->guestEmail, $this->stay, $this->adults, $this->children,
            $this->roomTypeId, $this->ratePlanId, $this->roomId, $this->notes, $this->total, $this->priceSnapshot, $this->oversold, $this->oversellReason,
            $reason ?? $this->statusReason, $this->createdBy, $this->lockVersion,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'guest_name' => $this->guestName,
            'guest_phone' => $this->guestPhone,
            'guest_email' => $this->guestEmail,
            'arrival' => $this->stay->arrival->toString(),
            'departure' => $this->stay->departure->toString(),
            'nights' => $this->stay->nightCount(),
            'adults' => $this->adults,
            'children' => $this->children,
            'room_type_id' => $this->roomTypeId,
            'rate_plan_id' => $this->ratePlanId,
            'room_id' => $this->roomId,
            'notes' => $this->notes,
            'currency' => $this->total->currency,
            'total_minor' => $this->total->amountMinor,
            'price_snapshot' => $this->priceSnapshot,
            'oversold' => $this->oversold,
            'oversell_reason' => $this->oversellReason,
            'status_reason' => $this->statusReason,
            'lock_version' => $this->lockVersion,
        ];
    }

    /**
     * Pure data for audit entries: no guest contact details, only what identifies the booking and its state.
     *
     * @return array<string, mixed>
     */
    public function auditView(): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'arrival' => $this->stay->arrival->toString(),
            'departure' => $this->stay->departure->toString(),
            'room_type_id' => $this->roomTypeId,
            'rate_plan_id' => $this->ratePlanId,
            'adults' => $this->adults,
            'children' => $this->children,
            'total_minor' => $this->total->amountMinor,
            'currency' => $this->total->currency,
            'oversold' => $this->oversold,
            'status_reason' => $this->statusReason,
        ];
    }
}
