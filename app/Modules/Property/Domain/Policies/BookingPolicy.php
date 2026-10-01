<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Policies;

use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * What a booking promises and what breaking it costs (FR-FO-009): whether a guarantee is needed, the deposit (none, the first
 * night, a percentage in basis points, or a fixed amount in minor units) and how many days before arrival it is due, how many
 * days before arrival a cancellation is still free, and the penalty for a later cancellation and for a no-show (none, the
 * first night, all nights, a percentage, or a fixed amount). It applies to a rate plan and a booking source, or to all of
 * either (`null`), from its start date; a change is a new version.
 */
final readonly class BookingPolicy
{
    public const DEPOSIT_BASES = ['none', 'first_night', 'percent', 'fixed'];

    public const PENALTY_KINDS = ['none', 'first_night', 'all_nights', 'percent', 'fixed'];

    public const SOURCES = ['direct', 'phone', 'ota', 'corporate', 'walk_in'];

    public function __construct(
        public string $id,
        public ?string $ratePlanId,
        public ?string $source,
        public BusinessDate $effectiveFrom,
        public bool $guaranteeRequired,
        public string $depositBasis,
        public int $depositValue,
        public int $depositDueDays,
        public int $cancelFreeDays,
        public string $cancelPenaltyKind,
        public int $cancelPenaltyValue,
        public string $noShowPenaltyKind,
        public int $noShowPenaltyValue,
    ) {
        if ($source !== null && ! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Choose a known booking source or none for all.');
        }

        foreach ([['deposit', $depositBasis, $depositValue, self::DEPOSIT_BASES], ['cancellation penalty', $cancelPenaltyKind, $cancelPenaltyValue, self::PENALTY_KINDS], ['no-show penalty', $noShowPenaltyKind, $noShowPenaltyValue, self::PENALTY_KINDS]] as [$label, $kind, $value, $allowed]) {
            if (! in_array($kind, $allowed, true)) {
                throw new InvalidArgumentException("Choose a valid {$label} type.");
            }

            if ($value < 0 || ($kind === 'percent' && $value > 10_000) || (in_array($kind, ['none', 'first_night', 'all_nights'], true) && $value !== 0)) {
                throw new InvalidArgumentException("The {$label} amount does not fit its type: a percentage is 0 to 100 and the other fixed types take no amount.");
            }
        }

        if ($depositDueDays > 365 || $cancelFreeDays > 365) {
            throw new InvalidArgumentException('Days before arrival are at most 365.');
        }

        if ($guaranteeRequired && $depositBasis === 'none') {
            throw new InvalidArgumentException('A guarantee needs a deposit: choose how much.');
        }

        if ($depositBasis === 'none' && $depositDueDays !== 0) {
            throw new InvalidArgumentException('Without a deposit there is no due date.');
        }
    }

    /** More specific policies win: the plan counts more than the source. */
    public function specificity(): int
    {
        return ($this->ratePlanId !== null ? 2 : 0) + ($this->source !== null ? 1 : 0);
    }

    public function appliesTo(string $ratePlanId, string $source, BusinessDate $on): bool
    {
        return ! $this->effectiveFrom->isAfter($on)
            && ($this->ratePlanId === null || $this->ratePlanId === $ratePlanId)
            && ($this->source === null || $this->source === $source);
    }

    /** @return array<string, mixed> in the form kept on a reservation as its policy snapshot */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'id' => $this->id,
            'rate_plan_id' => $this->ratePlanId,
            'source' => $this->source,
            'effective_from' => $this->effectiveFrom->toString(),
            'guarantee_required' => $this->guaranteeRequired,
            'deposit' => ['basis' => $this->depositBasis, 'value' => $this->depositValue, 'due_days_before_arrival' => $this->depositDueDays],
            'cancellation' => ['free_days_before_arrival' => $this->cancelFreeDays, 'penalty' => ['kind' => $this->cancelPenaltyKind, 'value' => $this->cancelPenaltyValue]],
            'no_show' => ['penalty' => ['kind' => $this->noShowPenaltyKind, 'value' => $this->noShowPenaltyValue]],
        ];
    }
}
