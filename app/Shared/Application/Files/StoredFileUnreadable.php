<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use RuntimeException;

final class StoredFileUnreadable extends RuntimeException
{
    public static function detected(): self
    {
        return new self('The stored file is missing, tampered with, or cannot be decrypted.');
    }
}
