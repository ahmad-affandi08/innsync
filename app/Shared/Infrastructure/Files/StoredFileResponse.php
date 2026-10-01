<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Files;

use App\Shared\Application\Files\FileContent;
use Symfony\Component\HttpFoundation\Response;

/** Module controllers call this only after DownloadFile succeeded; it never builds public URLs. */
final class StoredFileResponse
{
    public static function attachment(FileContent $content): Response
    {
        $name = $content->file->displayName ?? $content->file->id;
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'download';

        return new Response($content->contents, 200, [
            'Content-Type' => $content->file->mimeType,
            'Content-Length' => (string) strlen($content->contents),
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                $ascii,
                rawurlencode($name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
