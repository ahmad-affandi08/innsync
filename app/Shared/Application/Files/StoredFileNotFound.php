<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use RuntimeException;

/** Also raised for expired and cross-property files so existence is never disclosed. */
final class StoredFileNotFound extends RuntimeException
{
    public static function forId(string $fileId): self
    {
        return new self(sprintf('File %s was not found.', $fileId));
    }
}
