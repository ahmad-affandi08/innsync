<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Settings;

use App\Shared\Domain\Money\RoundingMode;
use App\Shared\Domain\Money\RoundingRule;
use App\Shared\Domain\Time\BusinessDate;
use DomainException;
use InvalidArgumentException;

/**
 * Operating settings of one property. Defaults follow docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md: check-in 14:00,
 * check-out 12:00, night audit from 23:00, whole-rupiah half-up rounding, a 365-day availability horizon (FR-FO-002).
 *
 * The business date is null until go-live. Once set it only moves forward and only night audit advances it (BR-001);
 * `initializeBusinessDate` is the one-time go-live step.
 */
final readonly class PropertySettings
{
    public function __construct(
        public ?BusinessDate $businessDate,
        public TimeOfDay $checkInTime,
        public TimeOfDay $checkOutTime,
        public TimeOfDay $nightAuditEarliest,
        public RoundingRule $rounding,
        public int $availabilityHorizonDays,
        public int $lockVersion,
    ) {
        if ($availabilityHorizonDays < 30 || $availabilityHorizonDays > 1095) {
            throw new InvalidArgumentException('The availability horizon is between 30 and 1095 days.');
        }
    }

    public static function defaults(): self
    {
        return new self(null, TimeOfDay::fromString('14:00'), TimeOfDay::fromString('12:00'), TimeOfDay::fromString('23:00'), RoundingRule::wholeUnits(2, RoundingMode::HalfUp), 365, 0);
    }

    public function revised(TimeOfDay $checkIn, TimeOfDay $checkOut, TimeOfDay $nightAuditEarliest, RoundingRule $rounding, int $horizonDays): self
    {
        return new self($this->businessDate, $checkIn, $checkOut, $nightAuditEarliest, $rounding, $horizonDays, $this->lockVersion);
    }

    public function initializeBusinessDate(BusinessDate $date): self
    {
        if ($this->businessDate !== null) {
            throw new DomainException('The business date is already set; only night audit advances it.');
        }

        return new self($date, $this->checkInTime, $this->checkOutTime, $this->nightAuditEarliest, $this->rounding, $this->availabilityHorizonDays, $this->lockVersion);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'business_date' => $this->businessDate?->toString(),
            'check_in_time' => $this->checkInTime->value,
            'check_out_time' => $this->checkOutTime->value,
            'night_audit_earliest_time' => $this->nightAuditEarliest->value,
            'rounding_increment_minor' => $this->rounding->incrementMinor,
            'rounding_mode' => $this->rounding->mode->value,
            'availability_horizon_days' => $this->availabilityHorizonDays,
            'lock_version' => $this->lockVersion,
        ];
    }
}
