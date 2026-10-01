<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Eloquent;

use App\Shared\Domain\Time\BusinessDate;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps a MySQL `DATE` column to `BusinessDate`. The value is a plain calendar
 * date and is never routed through a timestamp or a time zone.
 *
 * @implements CastsAttributes<BusinessDate, BusinessDate|string>
 */
final class BusinessDateCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BusinessDate
    {
        if ($value === null) {
            return null;
        }

        // A DATE column is returned as `YYYY-MM-DD`; tolerate a driver that appends a time.
        return BusinessDate::fromString(substr((string) $value, 0, 10));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BusinessDate => $value->toString(),
            is_string($value) => BusinessDate::fromString($value)->toString(),
            default => throw new InvalidArgumentException('A business date must be a BusinessDate or a YYYY-MM-DD string.'),
        };
    }
}
