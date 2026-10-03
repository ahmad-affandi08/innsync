<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use DateTimeImmutable;
use DateTimeZone;

/** A stored instant as the screens read it: ISO 8601 in UTC, with its offset. */
final class FnbTime
{
    public static function utc(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
