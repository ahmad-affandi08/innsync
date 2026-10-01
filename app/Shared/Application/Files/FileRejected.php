<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use RuntimeException;

final class FileRejected extends RuntimeException
{
    public static function empty(): self
    {
        return new self('The uploaded file is empty.');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(sprintf('The uploaded file exceeds the %d byte limit.', $maxBytes));
    }

    public static function unsupportedType(): self
    {
        return new self('The uploaded file content type is not permitted.');
    }

    public static function alreadyExpired(): self
    {
        return new self('The file expiry must be in the future.');
    }
}
