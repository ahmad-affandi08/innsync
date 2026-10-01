<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability;

final class SensitiveDataGuard
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'access_token',
        'api_key',
        'authorization',
        'card_number',
        'client_secret',
        'cookie',
        'credentials',
        'current_password',
        'cvc',
        'cvv',
        'document_number',
        'identity_number',
        'pan',
        'passport_number',
        'password',
        'password_confirmation',
        'pin',
        'private_key',
        'recovery_code',
        'recovery_codes',
        'refresh_token',
        'secret',
        'token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /** @param array<array-key, mixed> $payload */
    public static function assertSafe(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw SensitiveDataDetected::forKey($key);
            }

            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::redact($value);
            }
        }

        return $payload;
    }
}
