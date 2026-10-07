<?php

declare(strict_types=1);

namespace App\Shared\Application\Import;

/** The text of an uploaded CSV file. */
final class CsvText
{
    public static function fromFile(?string $path): string
    {
        $text = $path === null || $path === '' ? '' : (string) file_get_contents($path);

        // A file saved by a spreadsheet often starts with a byte order mark.
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}
