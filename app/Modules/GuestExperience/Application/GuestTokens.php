<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

/**
 * The random tokens of the QR codes and of the guest sessions (FR-GST-018). A token is 32 characters of at least 190 bits of randomness and says nothing about what it opens. It is looked up by its
 * hash, so a copy of the database does not open any code or session; the token of a QR code is also kept encrypted so the owner can print the code again.
 */
interface GuestTokens
{
    /** @return array{token: string, hash: string, cipher: string} a new token, its hash and the token encrypted */
    public function issue(): array;

    public function hash(string $token): string;

    /** @return string the token a cipher holds, or an empty string when it cannot be read */
    public function reveal(string $cipher): string;

    public function wellFormed(string $token): bool;
}
