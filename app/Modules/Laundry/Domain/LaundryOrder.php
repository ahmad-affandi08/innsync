<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Domain;

use DateTimeImmutable;

/**
 * A guest laundry order (FR-HK-020 to FR-HK-024, FR-LDY-001 to FR-LDY-004). It belongs to a stay, is counted by the laundry
 * before any work starts, moves through the processing steps in order and is charged to the folio when it is ready.
 */
final readonly class LaundryOrder
{
    /** @param list<LaundryLine> $lines */
    public function __construct(
        public string $id,
        public string $number,
        public string $barcode,
        public string $roomId,
        public string $stayId,
        public string $reservationId,
        public LaundryStatus $status,
        public bool $express,
        public string $pickupDate,
        public DateTimeImmutable $promisedAt,
        public ?string $notes,
        public bool $hasDiscrepancy,
        public ?string $discrepancyNote,
        public ?int $chargedMinor,
        public ?DateTimeImmutable $deliveredAt,
        public int $lockVersion,
        public array $lines,
    ) {
        if (preg_match('/^[A-Za-z0-9._\/-]{3,40}$/D', $barcode) !== 1) {
            throw LaundryRuleViolation::invalid('The bag tag is 3 to 40 letters, digits or . _ / -.', 'barcode');
        }

        if ($lines === []) {
            throw LaundryRuleViolation::invalid('An order lists at least one item.', 'lines');
        }

        if ($notes !== null && mb_strlen($notes) > 500) {
            throw LaundryRuleViolation::invalid('Notes are at most 500 characters.', 'notes');
        }
    }

    /**
     * The laundry counts what arrived. Every line needs its counted quantity; a difference is recorded, with a note, before the
     * order may go on (FR-LDY-002). Counting is only possible once.
     *
     * @param  array<string, int>  $counted  quantity by line id
     */
    public function receive(array $counted, ?string $note): self
    {
        if ($this->status !== LaundryStatus::Sent) {
            throw LaundryRuleViolation::notAllowed('Only an order that has just been sent can be counted.');
        }

        $lines = [];
        $differs = false;

        foreach ($this->lines as $line) {
            if (! isset($counted[$line->id]) || $counted[$line->id] < 0 || $counted[$line->id] > 999) {
                throw LaundryRuleViolation::invalid('Count every item, between 0 and 999.', 'counts');
            }

            $differs = $differs || $counted[$line->id] !== $line->quantity;
            $lines[] = $line->withVerified($counted[$line->id]);
        }

        $note = $note === null ? null : trim($note);

        if ($differs && ($note === null || $note === '' || mb_strlen($note) > 500)) {
            throw LaundryRuleViolation::invalid('The count differs from the list: record what was found.', 'note');
        }

        if (array_sum(array_map(static fn (LaundryLine $l): int => $l->billableQuantity(), $lines)) === 0) {
            throw LaundryRuleViolation::invalid('Nothing was received; cancel the order instead.', 'counts');
        }

        return $this->copy(LaundryStatus::Received, $differs, $differs ? $note : null, $this->chargedMinor, $this->deliveredAt, $lines);
    }

    /** Washing, drying, ironing in that order. Becoming ready is `markReady`, which carries the charge. */
    public function advance(): self
    {
        $next = $this->status->nextStep();

        if ($next === null || $next === LaundryStatus::Ready) {
            throw LaundryRuleViolation::notAllowed('This order cannot move to the next step from here.');
        }

        return $this->copy($next, $this->hasDiscrepancy, $this->discrepancyNote, $this->chargedMinor, $this->deliveredAt, $this->lines);
    }

    public function markReady(): self
    {
        if ($this->status !== LaundryStatus::Ironing) {
            throw LaundryRuleViolation::notAllowed('Only ironed laundry can be marked ready.');
        }

        return $this->copy(LaundryStatus::Ready, $this->hasDiscrepancy, $this->discrepancyNote, $this->billableMinor(), $this->deliveredAt, $this->lines);
    }

    public function deliver(DateTimeImmutable $at): self
    {
        if ($this->status !== LaundryStatus::Ready) {
            throw LaundryRuleViolation::notAllowed('Only laundry that is ready can be delivered.');
        }

        return $this->copy(LaundryStatus::Delivered, $this->hasDiscrepancy, $this->discrepancyNote, $this->chargedMinor, $at, $this->lines);
    }

    public function cancel(): self
    {
        if (! $this->status->canBeCancelled()) {
            throw LaundryRuleViolation::notAllowed('Work on this order has begun; it can no longer be cancelled.');
        }

        return $this->copy(LaundryStatus::Cancelled, $this->hasDiscrepancy, $this->discrepancyNote, $this->chargedMinor, $this->deliveredAt, $this->lines);
    }

    /** The price of what is billable: counted quantities times the prices copied at hand-over. */
    public function billableMinor(): int
    {
        return array_sum(array_map(static fn (LaundryLine $l): int => $l->totalMinor(), $this->lines));
    }

    /** Past its promised time and still not ready (FR-LDY-011). */
    public function isOverdue(DateTimeImmutable $now): bool
    {
        return $this->status->isActive() && $this->status !== LaundryStatus::Ready && $this->promisedAt < $now;
    }

    /** @param list<LaundryLine> $lines */
    private function copy(LaundryStatus $status, bool $discrepancy, ?string $note, ?int $charged, ?DateTimeImmutable $deliveredAt, array $lines): self
    {
        return new self(
            $this->id, $this->number, $this->barcode, $this->roomId, $this->stayId, $this->reservationId, $status, $this->express, $this->pickupDate, $this->promisedAt, $this->notes,
            $discrepancy, $note, $charged, $deliveredAt, $this->lockVersion, $lines,
        );
    }
}
