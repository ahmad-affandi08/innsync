<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use RuntimeException;

final class FileAccessDenied extends RuntimeException
{
    public static function forFile(string $fileId): self
    {
        return new self(sprintf('Access to file %s is denied.', $fileId));
    }
}
