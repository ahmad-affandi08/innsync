<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

use InvalidArgumentException;

/**
 * A table as a PDF file (FR-RPT-003): A4 on its side, a title and a line of context, the header row repeated on every page, numbers aligned to the right and the page number at the foot. It is written by
 * hand because the file is plain: one standard typeface (Helvetica, which every reader has, so nothing is embedded), text only, no pictures. A cell that does not fit its column is cut with an ellipsis
 * rather than run into the next one, and a long table runs over as many pages as it needs. The cells are what the CSV of the same report holds, so the two files say the same thing.
 */
final class PdfTable
{
    private const WIDTH = 841.89;

    private const HEIGHT = 595.28;

    private const MARGIN = 36.0;

    private const FONT = 8.0;

    private const LEADING = 12.0;

    private const MAX_COLUMNS = 24;

    /** @var list<string> */
    private array $objects = [];

    /**
     * @param  list<string>  $header
     * @param  list<list<scalar|null>>  $rows
     * @param  list<string>  $context  lines under the title (when it was made, for which dates, with which filters)
     */
    public static function build(string $title, array $context, array $header, array $rows): string
    {
        return (new self)->render($title, $context, $header, $rows);
    }

    /** The table of a CSV made by `CsvWriter`. */
    public static function fromCsv(string $title, array $context, string $csv): string
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            throw new InvalidArgumentException('The table could not be read.');
        }

        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];

        while (($line = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }

            // `CsvWriter` put an apostrophe in front of a cell that a spreadsheet could take for a formula; on paper it is just the text.
            $rows[] = array_map(static fn ($cell): string => preg_match("/^'[=+\\-@\t\r]/", (string) $cell) === 1 ? substr((string) $cell, 1) : (string) $cell, $line);
        }

        fclose($stream);
        $header = array_map('strval', array_shift($rows) ?? []);

        return self::build($title, $context, $header, $rows);
    }

    /**
     * @param  list<string>  $context
     * @param  list<string>  $header
     * @param  list<list<scalar|null>>  $rows
     */
    private function render(string $title, array $context, array $header, array $rows): string
    {
        $header = array_slice($header, 0, self::MAX_COLUMNS);
        $columns = max(1, count($header));
        $rows = array_map(static fn (array $r): array => array_map(static fn ($c): string => $c === null ? '' : (string) $c, array_slice(array_pad(array_values($r), $columns, ''), 0, $columns)), $rows);
        $usable = self::WIDTH - 2 * self::MARGIN;
        $widths = $this->widths($header, $rows, $usable);
        $numeric = $this->numericColumns($header, $rows);

        // Lay the rows out on pages first, so every page can say how many there are.
        $top = self::HEIGHT - self::MARGIN;
        $firstRoom = $top - 20 - self::LEADING * count($context) - 8;
        $bottom = self::MARGIN + 14;
        $pages = [];
        $current = [];
        $y = $firstRoom - self::LEADING;

        foreach ($rows as $row) {
            if ($y - self::LEADING < $bottom) {
                $pages[] = $current;
                $current = [];
                $y = $top - self::LEADING;
            }

            $current[] = $row;
            $y -= self::LEADING;
        }

        $pages[] = $current;
        $count = count($pages);
        $this->objects = [];
        $this->add('<< /Type /Catalog /Pages 2 0 R >>');
        $this->add('PAGES');
        $this->add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $this->add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $kids = [];

        foreach ($pages as $number => $slice) {
            $stream = '';
            $y = $top;

            if ($number === 0) {
                $stream .= $this->text(self::MARGIN, $y - 12, $title, 14, true);
                $y -= 20;

                foreach ($context as $line) {
                    $stream .= $this->text(self::MARGIN, $y - 9, $line, self::FONT);
                    $y -= self::LEADING;
                }

                $y -= 8;
            }

            $stream .= $this->row(self::MARGIN, $y, $header, $widths, array_fill(0, $columns, false), true);
            $stream .= $this->rule(self::MARGIN, $y - 3, $usable);
            $y -= self::LEADING;

            foreach ($slice as $row) {
                $stream .= $this->row(self::MARGIN, $y, $row, $widths, $numeric, false);
                $y -= self::LEADING;
            }

            $stream .= $this->text(self::WIDTH - self::MARGIN - 70, self::MARGIN - 4, sprintf('%d / %d', $number + 1, $count), self::FONT);
            $data = (string) gzcompress($stream);
            $content = $this->add('<< /Filter /FlateDecode /Length '.strlen($data).' >>', $data);
            $kids[] = $this->add(sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::WIDTH, self::HEIGHT, $content));
        }

        $this->objects[1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $kids)), $count);

        return $this->file();
    }

    /** Adds an object (and the stream it carries) and gives back its number, which is its position plus one. */
    private function add(string $dictionary, ?string $stream = null): int
    {
        $this->objects[] = $stream === null ? $dictionary : $dictionary."\nstream\n".$stream."\nendstream";

        return count($this->objects);
    }

    private function file(): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($this->objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($this->objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out.'trailer'."\n<< /Size ".(count($this->objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
    }

    /**
     * @param  list<string>  $cells
     * @param  list<float>  $widths
     * @param  list<bool>  $right
     */
    private function row(float $x, float $y, array $cells, array $widths, array $right, bool $bold): string
    {
        $out = '';

        foreach ($cells as $i => $cell) {
            $fits = $this->fit($cell, $widths[$i] - 4, $bold);
            $at = $x + 2;

            if ($right[$i] ?? false) {
                $at = $x + $widths[$i] - 2 - $this->measure($fits, $bold);
            }

            $out .= $this->text($at, $y - 9, $fits, self::FONT, $bold);
            $x += $widths[$i];
        }

        return $out;
    }

    private function rule(float $x, float $y, float $length): string
    {
        return sprintf("0.5 w %.2F %.2F m %.2F %.2F l S\n", $x, $y, $x + $length, $y);
    }

    private function text(float $x, float $y, string $text, float $size, bool $bold = false): string
    {
        return sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $this->escape($text));
    }

    /** Windows-1252, the encoding the font is declared with, with the characters PDF strings treat specially escaped. */
    private function escape(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B-\x1F]/', ' ', $text) ?? '';
        $encoded = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')', "\r", "\n", "\t"], ['\\\\', '\\(', '\\)', ' ', ' ', ' '], $encoded);
    }

    /** @param list<string> $header @param list<list<string>> $rows @return list<float> */
    private function widths(array $header, array $rows, float $usable): array
    {
        $want = [];

        foreach ($header as $i => $name) {
            $longest = $this->measure($name, true);

            foreach (array_slice($rows, 0, 400) as $row) {
                $longest = max($longest, $this->measure($row[$i] ?? '', false));
            }

            $want[$i] = min(max($longest + 8, 28.0), 190.0);
        }

        $total = array_sum($want);

        return array_map(static fn (float $w): float => $w * ($usable / $total), $want);
    }

    /** @param list<string> $header @param list<list<string>> $rows @return list<bool> */
    private function numericColumns(array $header, array $rows): array
    {
        $result = [];

        foreach (array_keys($header) as $i) {
            $values = array_filter(array_column($rows, $i), static fn (string $v): bool => $v !== '');
            $result[$i] = $values !== [] && count(array_filter($values, static fn (string $v): bool => preg_match('/^[-+]?[\d.,]+%?$/D', $v) === 1)) === count($values);
        }

        return $result;
    }

    private function fit(string $text, float $room, bool $bold): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($this->measure($text, $bold) <= $room) {
            return $text;
        }

        while ($text !== '' && $this->measure($text.'…', $bold) > $room) {
            $text = mb_substr($text, 0, -1);
        }

        return $text.'…';
    }

    /** The width of a text in points, from the widths of the Helvetica letters grouped by how wide they are. */
    private function measure(string $text, bool $bold): float
    {
        $units = 0.0;

        foreach (mb_str_split($text) as $char) {
            $units += match (true) {
                str_contains("iIjl.,;:!|'`", $char) => 0.28,
                str_contains('ftr()[]- /', $char) => 0.34,
                str_contains('mwMW@%', $char) => 0.86,
                $char >= 'A' && $char <= 'Z' => 0.67,
                $char >= '0' && $char <= '9' => 0.556,
                default => 0.52,
            };
        }

        return $units * self::FONT * ($bold ? 1.06 : 1.0);
    }
}
