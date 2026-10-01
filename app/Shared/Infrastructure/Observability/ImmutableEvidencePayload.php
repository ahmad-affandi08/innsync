<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

final class ImmutableEvidencePayload
{
    /** @param array<string, mixed> $payload */
    public static function checksum(array $payload): string
    {
        return hash('sha256', self::encode(self::canonicalize($payload)));
    }

    /** @param array<string, mixed>|null $value */
    public static function encode(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function minimumRetentionUntil(
        CarbonImmutable $occurredAt,
        mixed $configuredDays,
    ): ?CarbonImmutable {
        if ($configuredDays === null || $configuredDays === '') {
            return null;
        }

        if (! is_numeric($configuredDays) || (int) $configuredDays < 1) {
            throw new InvalidArgumentException('Evidence retention days must be a positive integer or null.');
        }

        return $occurredAt->addDays((int) $configuredDays);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    private static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
