<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Privacy;

use App\Shared\Application\Privacy\FieldCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

final readonly class LaravelFieldCipher implements FieldCipher
{
    public function __construct(private Encrypter $encrypter, private string $indexKey)
    {
        if ($indexKey === '') {
            throw new RuntimeException('A blind-index key is required.');
        }
    }

    public function seal(string $plain): string
    {
        return $this->encrypter->encryptString($plain);
    }

    public function open(string $sealed): string
    {
        try {
            return $this->encrypter->decryptString($sealed);
        } catch (DecryptException) {
            throw new RuntimeException('A protected field could not be read.');
        }
    }

    public function blindIndex(string $scope, string $normalized): string
    {
        return hash_hmac('sha256', $scope."\0".$normalized, $this->indexKey);
    }
}
