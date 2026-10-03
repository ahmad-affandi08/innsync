<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\GuestTokens;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final readonly class RandomGuestTokens implements GuestTokens
{
    public function issue(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => $this->hash($token), 'cipher' => Crypt::encryptString($token)];
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function reveal(string $cipher): string
    {
        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            return '';
        }
    }

    public function wellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{32}$/D', $token) === 1;
    }
}
