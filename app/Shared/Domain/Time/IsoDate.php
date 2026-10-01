<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A calendar date with no time and no time zone (`YYYY-MM-DD`).
 *
 * Shared behavior for two deliberately distinct concepts: `BusinessDate` (the
 * operating date a property posts to, BR-001) and `CalendarDate` (the clock
 * date at a moment in a property's time zone). They never convert implicitly
 * and never compare with each other, so the two can not be confused (NFR-26).
 */
abstract readonly class IsoDate
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    private DateTimeImmutable $midnightUtc;

    final protected function __construct(private string $value)
    {
        // Calendar arithmetic runs on UTC midnight, which has no daylight-saving gaps.
        $this->midnightUtc = new DateTimeImmutable($value.' 00:00:00', new DateTimeZone('UTC'));
    }

    final public static function fromString(string $value): static
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid %s (YYYY-MM-DD).', $value, static::label()));
        }

        return new static($value);
    }

    abstract protected static function label(): string;

    final public function toString(): string
    {
        return $this->value;
    }

    final public function __toString(): string
    {
        return $this->value;
    }

    final public function addDays(int $days): static
    {
        return new static($this->midnightUtc->modify(sprintf('%+d days', $days))->format('Y-m-d'));
    }

    final public function next(): static
    {
        return $this->addDays(1);
    }

    final public function previous(): static
    {
        return $this->addDays(-1);
    }

    /** Whole days from this date to `$other` (negative when `$other` is earlier). */
    final public function daysUntil(self $other): int
    {
        $this->assertSameKind($other);

        return (int) $this->midnightUtc->diff($other->midnightUtc)->format('%r%a');
    }

    final public function equals(self $other): bool
    {
        $this->assertSameKind($other);

        return $this->value === $other->value;
    }

    final public function isBefore(self $other): bool
    {
        $this->assertSameKind($other);

        return $this->value < $other->value;
    }

    final public function isAfter(self $other): bool
    {
        $this->assertSameKind($other);

        return $this->value > $other->value;
    }

    /** @internal for time-zone conversion inside the Time domain package */
    final public function midnightUtc(): DateTimeImmutable
    {
        return $this->midnightUtc;
    }

    private function assertSameKind(self $other): void
    {
        if ($other::class !== static::class) {
            throw new InvalidArgumentException(sprintf(
                'A %s can not be compared with a %s.',
                static::label(),
                $other::label(),
            ));
        }
    }
}
