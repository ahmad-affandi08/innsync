<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use InvalidArgumentException;

/**
 * Upload rules declared by the owning module. Shared code guesses no business policy:
 * MIME allow-list and size ceiling are always explicit.
 */
final readonly class FilePolicy
{
    /** @param list<string> $allowedMimeTypes */
    public function __construct(
        public array $allowedMimeTypes,
        public int $maxBytes,
        public FileSensitivity $sensitivity = FileSensitivity::Sensitive,
        public bool $requiresExpiry = false,
    ) {
        if ($allowedMimeTypes === []) {
            throw new InvalidArgumentException('A file policy requires at least one allowed MIME type.');
        }

        foreach ($allowedMimeTypes as $mime) {
            if (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) !== 1) {
                throw new InvalidArgumentException('Allowed MIME types must be lowercase type/subtype values.');
            }
        }

        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The maximum file size must be positive.');
        }
    }
}
