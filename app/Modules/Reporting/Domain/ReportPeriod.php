<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * A closed range of business dates (BR-001) that every dashboard card and report of one request is read for (FR-DSH-015).
 * Presets are resolved against the property's current business date, never the clock.
 */
final readonly class ReportPeriod
{
    public const PRESETS = ['today', 'yesterday', 'last7', 'month', 'custom'];

    public const MAX_DAYS = 400;

    private function __construct(public string $preset, public BusinessDate $from, public BusinessDate $to)
    {
        if ($to->isBefore($from)) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        if ($from->daysUntil($to) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('A period is at most '.self::MAX_DAYS.' days.');
        }
    }

    public static function preset(string $preset, BusinessDate $today): self
    {
        return match ($preset) {
            'today' => new self('today', $today, $today),
            'yesterday' => new self('yesterday', $today->previous(), $today->previous()),
            'last7' => new self('last7', $today->addDays(-6), $today),
            'month' => new self('month', BusinessDate::fromString(substr($today->toString(), 0, 8).'01'), $today),
            default => throw new InvalidArgumentException('Unknown period.'),
        };
    }

    public static function custom(BusinessDate $from, BusinessDate $to): self
    {
        return new self('custom', $from, $to);
    }

    /** From a request: a preset name, or a custom range when both dates are given. */
    public static function fromInput(?string $preset, ?string $from, ?string $to, BusinessDate $today): self
    {
        if ($from !== null && $from !== '' && $to !== null && $to !== '') {
            return self::custom(BusinessDate::fromString($from), BusinessDate::fromString($to));
        }

        return self::preset($preset === null || $preset === '' || $preset === 'custom' ? 'today' : $preset, $today);
    }

    public function days(): int
    {
        return $this->from->daysUntil($this->to) + 1;
    }

    /** The same number of days directly before this period. */
    public function previous(): self
    {
        return new self('previous', $this->from->addDays(-$this->days()), $this->from->previous());
    }

    /** The same dates one week earlier. */
    public function weekEarlier(): self
    {
        return new self('week', $this->from->addDays(-7), $this->to->addDays(-7));
    }

    /** The same dates one month earlier; a day that does not exist there becomes the last day of that month. */
    public function monthEarlier(): self
    {
        return new self('month_earlier', self::minusMonth($this->from), self::minusMonth($this->to));
    }

    private static function minusMonth(BusinessDate $date): BusinessDate
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date->toString()));
        $m--;

        if ($m === 0) {
            $m = 12;
            $y--;
        }

        return BusinessDate::fromString(sprintf('%04d-%02d-%02d', $y, $m, min($d, (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'))));
    }

    /** @return array{preset: string, from: string, to: string} */
    public function toArray(): array
    {
        return ['preset' => $this->preset, 'from' => $this->from->toString(), 'to' => $this->to->toString()];
    }
}
