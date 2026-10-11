<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Time\DateMath;

/**
 * Reads the CSV a bank gives (internet banking download) into lines, with no access to anything. Banks name their columns differently, so a column is known by any of its usual names (English or Indonesian), the
 * separator may be a comma, a semicolon or a tab, a date may be year-month-day or day/month/year, and an amount may be written 1,234,567.89 or 1.234.567,89, with or without CR/DB after it. A line that cannot be
 * read is named with its line number and nothing is imported. Nothing is guessed about which column is which beyond those names.
 */
final class BankStatementParser
{
    public const MAX_ROWS = 3000;

    private const NAMES = [
        'date' => ['date', 'tanggal', 'tgl', 'transaction date', 'tanggal transaksi', 'posting date', 'value date'],
        'description' => ['description', 'keterangan', 'uraian', 'remark', 'remarks', 'narrative', 'detail', 'transaction details', 'berita'],
        'reference' => ['reference', 'referensi', 'ref', 'no. ref', 'no ref', 'trx id', 'reference no', 'no. referensi'],
        'debit' => ['debit', 'db', 'keluar', 'withdrawal', 'pengeluaran', 'uang keluar'],
        'credit' => ['credit', 'cr', 'kredit', 'masuk', 'deposit', 'penerimaan', 'uang masuk'],
        'amount' => ['amount', 'jumlah', 'nominal', 'mutasi'],
        'balance' => ['balance', 'saldo', 'saldo akhir'],
    ];

    /** @return array{lines: list<array{line: int, date: string, description: string, reference: string|null, direction: string, amount_minor: int, balance_minor: int|null}>, errors: list<array{line: int, code: string, message: string}>} */
    public static function parse(string $csv): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $rows = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $headerAt = null;

        foreach ($rows as $i => $text) {
            if (trim($text) !== '') {
                $headerAt = $i;

                break;
            }
        }

        if ($headerAt === null) {
            return ['lines' => [], 'errors' => [['line' => 0, 'code' => 'empty_file', 'message' => 'The file is empty.']]];
        }

        $delimiter = self::delimiter($rows[$headerAt]);
        $header = array_map(static fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv($rows[$headerAt], $delimiter, '"', ''));
        $column = [];

        foreach (self::NAMES as $field => $names) {
            foreach ($header as $index => $name) {
                if (in_array($name, $names, true)) {
                    $column[$field] ??= $index;
                }
            }
        }

        $errors = [];

        foreach (['date', 'description'] as $need) {
            if (! isset($column[$need])) {
                $errors[] = ['line' => $headerAt + 1, 'code' => 'missing_column', 'message' => $need];
            }
        }

        if (! isset($column['amount']) && ! (isset($column['debit']) || isset($column['credit']))) {
            $errors[] = ['line' => $headerAt + 1, 'code' => 'missing_column', 'message' => 'amount (or debit and credit)'];
        }

        if ($errors !== []) {
            return ['lines' => [], 'errors' => $errors];
        }

        $lines = [];

        foreach (array_slice($rows, $headerAt + 1, null, true) as $i => $text) {
            if (trim($text) === '') {
                continue;
            }

            $cells = str_getcsv($text, $delimiter, '"', '');
            $cell = static fn (string $field): string => isset($column[$field]) ? trim((string) ($cells[$column[$field]] ?? '')) : '';
            $date = self::date($cell('date'));

            if ($date === null) {
                $errors[] = ['line' => $i + 1, 'code' => 'bad_date', 'message' => $cell('date')];

                continue;
            }

            $debit = self::amount($cell('debit'));
            $credit = self::amount($cell('credit'));
            $amount = self::amount($cell('amount'));
            $direction = null;
            $minor = null;

            if (isset($column['amount']) && $cell('amount') !== '' && $amount !== null) {
                $minor = abs($amount['minor']);
                $direction = $amount['sign'] === 'out' || $amount['minor'] < 0 ? 'out' : 'in';
            } elseif ($debit !== null && $debit['minor'] !== 0) {
                [$minor, $direction] = [abs($debit['minor']), 'out'];
            } elseif ($credit !== null && $credit['minor'] !== 0) {
                [$minor, $direction] = [abs($credit['minor']), 'in'];
            }

            if ($minor === null || $minor < 1 || $direction === null) {
                $errors[] = ['line' => $i + 1, 'code' => 'bad_amount', 'message' => trim($cell('amount').' '.$cell('debit').' '.$cell('credit'))];

                continue;
            }

            $description = mb_substr(trim((string) preg_replace('/\s+/', ' ', $cell('description'))), 0, 250);
            $reference = $cell('reference') === '' ? null : mb_substr($cell('reference'), 0, 60);
            $balance = self::amount($cell('balance'));
            $lines[] = ['line' => $i + 1, 'date' => $date, 'description' => $description, 'reference' => $reference, 'direction' => $direction, 'amount_minor' => $minor, 'balance_minor' => $balance === null ? null : $balance['minor']];

            if (count($lines) > self::MAX_ROWS) {
                return ['lines' => [], 'errors' => [['line' => $i + 1, 'code' => 'too_many', 'message' => (string) self::MAX_ROWS]]];
            }
        }

        if ($lines === [] && $errors === []) {
            $errors[] = ['line' => 0, 'code' => 'no_rows', 'message' => 'The file has a header and no lines.'];
        }

        return ['lines' => $errors === [] ? $lines : [], 'errors' => $errors];
    }

    /** `YYYY-MM-DD`, `DD/MM/YYYY`, `DD-MM-YYYY`, `DD/MM/YY` (a two-digit year is 20xx). */
    public static function date(string $text): ?string
    {
        $text = trim($text);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $text, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})(?:[ T].*)?$/', $text, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Money in minor units (sen) from 1,234,567.89 / 1.234.567,89 / 1234567 / -50.000 and a CR or DB after it.
     *
     * @return array{minor: int, sign: string|null}|null
     */
    public static function amount(string $text): ?array
    {
        $text = trim(str_ireplace(['Rp', 'IDR'], '', $text));

        if ($text === '') {
            return null;
        }

        $sign = null;

        if (preg_match('/\s*(CR|DB|DR)\.?$/i', $text, $m) === 1) {
            $sign = strtoupper($m[1]) === 'CR' ? 'in' : 'out';
            $text = trim((string) preg_replace('/\s*(CR|DB|DR)\.?$/i', '', $text));
        }

        $negative = str_starts_with($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')'));
        $text = trim($text, "-+() \t");

        if (preg_match('/^[\d.,]+$/', $text) !== 1) {
            return null;
        }

        $lastDot = strrpos($text, '.');
        $lastComma = strrpos($text, ',');
        $decimal = null;

        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastComma !== false && substr_count($text, ',') === 1 && strlen($text) - $lastComma - 1 !== 3) {
            $decimal = ',';
        } elseif ($lastDot !== false && substr_count($text, '.') === 1 && strlen($text) - $lastDot - 1 !== 3) {
            $decimal = '.';
        }

        if ($decimal === null) {
            $whole = (string) preg_replace('/[.,]/', '', $text);
            $fraction = '00';
        } else {
            $at = strrpos($text, $decimal);
            $whole = (string) preg_replace('/[.,]/', '', substr($text, 0, (int) $at));
            $fraction = substr(substr($text, (int) $at + 1).'00', 0, 2);
        }

        if ($whole === '' || strlen($whole) > 15) {
            return null;
        }

        $minor = (int) $whole * 100 + (int) $fraction;

        return ['minor' => $negative ? -$minor : $minor, 'sign' => $sign];
    }

    private static function delimiter(string $headerLine): string
    {
        $best = ',';
        $most = 0;

        foreach ([',', ';', "\t"] as $d) {
            $n = substr_count($headerLine, $d);

            if ($n > $most) {
                [$best, $most] = [$d, $n];
            }
        }

        return $best;
    }

    public static function fingerprint(string $account, string $date, string $direction, int $minor, string $description, ?string $reference, int $occurrence): string
    {
        return hash('sha256', implode('|', [strtolower(trim($account)), $date, $direction, $minor, strtolower($description), strtolower((string) $reference), $occurrence]));
    }

    /** Whole days between two dates, for matching within a window. */
    public static function days(string $a, string $b): int
    {
        return abs(DateMath::daysBetween($a, $b));
    }
}
