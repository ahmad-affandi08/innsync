<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Files;

use App\Shared\Application\Files\ContentInspector;
use finfo;

final readonly class FinfoContentInspector implements ContentInspector
{
    public function detectMimeType(string $contents): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }
}
