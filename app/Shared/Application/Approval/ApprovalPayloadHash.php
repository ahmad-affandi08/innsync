<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

use InvalidArgumentException;

/**
 * Fingerprint of the exact change an approver is shown. The module hashes the
 * payload when it asks for approval and again when it executes, so an approval
 * for one change can never authorize a different one (approve a 10% discount,
 * apply 100%). Key order does not matter; floats are refused because money is
 * integer minor units (ADR-0006).
 */
final class ApprovalPayloadHash
{
    /** @param array<array-key, mixed> $payload */
    public static function of(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('An approval payload must not contain floats; use integer minor units or strings.');
        }

        if (! is_array($value)) {
            if (! is_null($value) && ! is_scalar($value)) {
                throw new InvalidArgumentException('An approval payload may hold only scalars, null and arrays.');
            }

            return $value;
        }

        $normalized = array_map(self::canonical(...), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }
}
