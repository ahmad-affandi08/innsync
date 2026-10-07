<?php

declare(strict_types=1);

namespace App\Shared\Application\Import;

/**
 * A spreadsheet saved as CSV, read into rows with the line each came from, so a bad row can be named. The first line is the header. A comma, a semicolon
 * (a spreadsheet in Indonesian settings) or a tab separates the cells. Nothing is guessed: a missing required column or an empty required cell refuses the file.
 */
final class CsvRows
{
    public const MAX_ROWS = 300;

    /**
     * @param  list<string>  $required  header names that must exist and be filled in every row
     * @param  list<string>  $optional  header names that may exist
     * @return array{rows: list<array{line: int, values: array<string, string>}>, errors: list<array{line: int, code: string, message: string}>}
     */
    public static function parse(string $csv, array $required, array $optional = []): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $headerLine = '';

        foreach ($lines as $candidate) {
            if (trim($candidate) !== '') {
                $headerLine = $candidate;

                break;
            }
        }

        if ($headerLine === '') {
            return ['rows' => [], 'errors' => [['line' => 0, 'code' => 'empty_file', 'message' => 'The file is empty.']]];
        }

        $delimiter = self::delimiter($headerLine);
        $header = array_map(static fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv($headerLine, $delimiter, '"', ''));
        $errors = [];

        foreach ($required as $name) {
            if (! in_array($name, $header, true)) {
                $errors[] = ['line' => 1, 'code' => 'missing_column', 'message' => $name];
            }
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }

        $known = array_merge($required, $optional);
        $rows = [];
        $seenHeader = false;

        foreach ($lines as $index => $text) {
            if (! $seenHeader) {
                if ($text === $headerLine) {
                    $seenHeader = true;
                }

                continue;
            }

            if (trim($text) === '') {
                continue;
            }

            $cells = str_getcsv($text, $delimiter, '"', '');
            $values = [];

            foreach ($header as $position => $name) {
                if (in_array($name, $known, true)) {
                    $values[$name] = trim((string) ($cells[$position] ?? ''));
                }
            }

            foreach ($required as $name) {
                if (($values[$name] ?? '') === '') {
                    $errors[] = ['line' => $index + 1, 'code' => 'empty_cell', 'message' => $name];
                }
            }

            $rows[] = ['line' => $index + 1, 'values' => $values];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['line' => 0, 'code' => 'no_rows', 'message' => 'The file has a header and no rows.'];
        }

        if (count($rows) > self::MAX_ROWS) {
            $errors[] = ['line' => 0, 'code' => 'too_many', 'message' => (string) self::MAX_ROWS];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** The separator the header line uses most. */
    private static function delimiter(string $headerLine): string
    {
        $best = ',';
        $most = 0;

        foreach ([',', ';', "\t"] as $candidate) {
            $count = substr_count($headerLine, $candidate);

            if ($count > $most) {
                $best = $candidate;
                $most = $count;
            }
        }

        return $best;
    }
}
