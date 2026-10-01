<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

interface ContentInspector
{
    /** Detects the MIME type from content, never from client-declared names or headers. */
    public function detectMimeType(string $contents): string;
}
