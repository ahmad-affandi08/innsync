<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

final readonly class FileContent
{
    public function __construct(
        public StoredFile $file,
        public string $contents,
    ) {}
}
