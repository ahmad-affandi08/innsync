<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Canonical handling of stored/exchanged instants (NFR-26): always UTC, with
 * microsecond precision and an explicit `Z`, so a value never depends on the
 * server's, database's, or reader's local zone.
 */
final class UtcTime
{
    public const FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public static function normalize(DateTimeInterface $instant): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function format(DateTimeInterface $instant): string
    {
        return self::normalize($instant)->format(self::FORMAT);
    }

    /** Parses an RFC 3339 / ISO 8601 instant that carries an explicit offset or `Z`. */
    public static function parse(string $value): DateTimeImmutable
    {
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/iD', $value) !== 1) {
            throw new InvalidArgumentException('An instant must include a UTC offset or Z; a bare local time is ambiguous.');
        }

        try {
            return self::normalize(new DateTimeImmutable($value));
        } catch (\Exception) {
            throw new InvalidArgumentException('The value is not a valid instant.');
        }
    }
}
