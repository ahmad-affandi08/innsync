<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

final class PhoneNumber
{
    /** Digits only, with the country code: 0812… and 812… become 62812…; null when it cannot be a mobile number. */
    public static function international(string $text): ?string
    {
        $digits = preg_replace('/\D/', '', $text) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $digits) === 1 ? $digits : null;
    }
}
