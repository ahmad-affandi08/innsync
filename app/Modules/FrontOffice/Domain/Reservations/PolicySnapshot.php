<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Reservations;

use App\Shared\Domain\Time\BusinessDate;

/**
 * The booking policy a reservation was given when it was made (FR-FO-009), read back to work out its deposit and what a
 * cancellation or no-show costs. It never looks at the current policy: what was promised stays promised (BR-002).
 *
 * Penalties are fees on the booked room price, before service charge and tax; whether a penalty is taxable is a Finance decision
 * (PRD Q-13) and none is added here. A deposit is money received, so it is worked out on the price including charges.
 */
final readonly class PolicySnapshot
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self($data);
    }

    public function guaranteeRequired(): bool
    {
        return (bool) ($this->data['guarantee_required'] ?? false);
    }

    /** @param list<array<string, mixed>> $nights */
    public function depositRequiredMinor(array $nights, int $totalMinor): int
    {
        $basis = $this->data['deposit']['basis'] ?? 'none';
        $value = (int) ($this->data['deposit']['value'] ?? 0);

        return match ($basis) {
            'first_night' => min($totalMinor, (int) ($nights[0]['total_minor'] ?? 0)),
            'percent' => self::percent($totalMinor, $value),
            'fixed' => min($totalMinor, $value),
            default => 0,
        };
    }

    /** The deposit is due this many days before arrival, but never before the day the reservation was made. */
    public function depositDueDate(BusinessDate $arrival, BusinessDate $bookedOn): ?BusinessDate
    {
        if (($this->data['deposit']['basis'] ?? 'none') === 'none') {
            return null;
        }

        $due = $arrival->addDays(-(int) ($this->data['deposit']['due_days_before_arrival'] ?? 0));

        return $due->isBefore($bookedOn) ? $bookedOn : $due;
    }

    /** The last business date on which a cancellation is free. */
    public function freeCancellationUntil(BusinessDate $arrival): BusinessDate
    {
        return $arrival->addDays(-(int) ($this->data['cancellation']['free_days_before_arrival'] ?? 0));
    }

    /**
     * @param  list<array<string, mixed>>  $nights
     * @return array{amount_minor: int, free: bool, kind: string}
     */
    public function cancellationPenalty(BusinessDate $today, BusinessDate $arrival, array $nights): array
    {
        $kind = (string) ($this->data['cancellation']['penalty']['kind'] ?? 'none');

        if ($kind === 'none' || ! $today->isAfter($this->freeCancellationUntil($arrival))) {
            return ['amount_minor' => 0, 'free' => true, 'kind' => $kind];
        }

        return ['amount_minor' => $this->penalty($kind, (int) ($this->data['cancellation']['penalty']['value'] ?? 0), $nights), 'free' => false, 'kind' => $kind];
    }

    /**
     * @param  list<array<string, mixed>>  $nights
     * @return array{amount_minor: int, free: bool, kind: string}
     */
    public function noShowPenalty(array $nights): array
    {
        $kind = (string) ($this->data['no_show']['penalty']['kind'] ?? 'none');

        return ['amount_minor' => $kind === 'none' ? 0 : $this->penalty($kind, (int) ($this->data['no_show']['penalty']['value'] ?? 0), $nights), 'free' => $kind === 'none', 'kind' => $kind];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @param list<array<string, mixed>> $nights */
    private function penalty(string $kind, int $value, array $nights): int
    {
        $base = array_sum(array_map(static fn (array $n): int => (int) ($n['base_minor'] ?? 0), $nights));

        return match ($kind) {
            'first_night' => (int) ($nights[0]['base_minor'] ?? 0),
            'all_nights' => $base,
            'percent' => self::percent($base, $value),
            'fixed' => $value,
            default => 0,
        };
    }

    /** Basis points, rounded half up to the minor unit. */
    private static function percent(int $amount, int $basisPoints): int
    {
        return intdiv($amount * $basisPoints + 5_000, 10_000);
    }
}
