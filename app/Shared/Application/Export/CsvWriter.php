<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

/** Builds a spreadsheet-safe CSV: UTF-8 with a byte order mark, quoted cells, and no cell that a spreadsheet could run as a formula. */
final class CsvWriter
{
    /**
     * @param  list<string>  $header
     * @param  list<list<scalar|null>>  $rows
     */
    public static function build(array $header, array $rows): string
    {
        $out = "\xEF\xBB\xBF".self::line($header);

        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /** @param list<scalar|null> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(static function ($cell): string {
            $text = $cell === null ? '' : (string) $cell;

            // A cell that starts like a formula is made harmless (OWASP CSV injection guidance); a plain negative number stays a number.
            if ($text !== '' && str_contains("=+-@\t\r", $text[0]) && preg_match('/^-?\d+(\.\d+)?$/D', $text) !== 1) {
                $text = "'".$text;
            }

            return '"'.str_replace('"', '""', $text).'"';
        }, $cells))."\r\n";
    }
}
