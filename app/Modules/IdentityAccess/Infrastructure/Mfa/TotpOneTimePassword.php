<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Mfa;

use App\Modules\IdentityAccess\Application\Ports\OneTimePassword;
use InvalidArgumentException;

final class TotpOneTimePassword implements OneTimePassword
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $accountName): string
    {
        $issuer = (string) config('app.name');
        $label = rawurlencode($issuer.':'.$accountName);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            (int) config('identity_access.totp.digits'),
            (int) config('identity_access.totp.period_seconds'),
        );
    }

    public function verify(string $secret, string $code): bool
    {
        $digits = (int) config('identity_access.totp.digits');

        if (preg_match('/^\d{'.$digits.'}$/', $code) !== 1) {
            return false;
        }

        $counter = intdiv(time(), (int) config('identity_access.totp.period_seconds'));
        $window = (int) config('identity_access.totp.allowed_window');

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->codeForCounter($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        return $this->codeForCounter(
            $secret,
            intdiv($timestamp, (int) config('identity_access.totp.period_seconds')),
        );
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $modulus = 10 ** (int) config('identity_access.totp.digits');

        return str_pad(
            (string) ($binary % $modulus),
            (int) config('identity_access.totp.digits'),
            '0',
            STR_PAD_LEFT,
        );
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $bits = '';

        foreach (str_split(strtoupper($encoded)) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position === false) {
                throw new InvalidArgumentException('Invalid base32 TOTP secret.');
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
