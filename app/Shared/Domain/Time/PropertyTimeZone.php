<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A property's IANA time zone (NFR-26). Instants are stored and exchanged in
 * UTC; this type converts at the edges: to the property's wall clock for
 * display and calendar dates, and back to UTC for local times entered by staff.
 *
 * Only region identifiers (`Asia/Jakarta`, `UTC`) are accepted. Abbreviations
 * and fixed offsets (`WIB`, `+07:00`) carry no daylight-saving rules and are
 * rejected.
 */
final readonly class PropertyTimeZone
{
    private function __construct(private string $identifier) {}

    public static function fromIdentifier(string $identifier): self
    {
        if (! in_array($identifier, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            throw new InvalidArgumentException('The time zone must be an IANA region identifier such as Asia/Jakarta.');
        }

        return new self($identifier);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function equals(self $other): bool
    {
        return $this->identifier === $other->identifier;
    }

    /** The same instant expressed on this property's wall clock. */
    public function localize(DateTimeInterface $instant): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone($this->zone());
    }

    /** The clock date at `$instant` in this property. */
    public function calendarDateAt(DateTimeInterface $instant): CalendarDate
    {
        return CalendarDate::fromString($this->localize($instant)->format('Y-m-d'));
    }

    /**
     * UTC instant of a wall-clock time on a calendar date (`H:i` or `H:i:s`).
     *
     * A time skipped by a daylight-saving gap is rejected rather than silently
     * shifted. A time repeated at the end of daylight saving resolves to its
     * first occurrence.
     */
    public function utcAt(CalendarDate $date, string $time = '00:00'): DateTimeImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/D', $time) !== 1) {
            throw new InvalidArgumentException('The time must be HH:MM or HH:MM:SS.');
        }

        $local = $date->toString().' '.(strlen($time) === 5 ? $time.':00' : $time);
        $resolved = new DateTimeImmutable($local, $this->zone());

        if ($resolved->format('Y-m-d H:i:s') !== $local) {
            throw NonexistentLocalTime::at($local, $this->identifier);
        }

        return $resolved->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Half-open UTC range `[start, end)` covering a calendar date, for filtering
     * by clock date. A day lasts 23 or 25 hours across a daylight-saving change;
     * if midnight itself is skipped the day starts at its first valid instant.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function utcRangeOf(CalendarDate $date): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            (new DateTimeImmutable($date->toString().' 00:00:00', $this->zone()))->setTimezone($utc),
            (new DateTimeImmutable($date->next()->toString().' 00:00:00', $this->zone()))->setTimezone($utc),
        ];
    }

    private function zone(): DateTimeZone
    {
        return new DateTimeZone($this->identifier);
    }
}
