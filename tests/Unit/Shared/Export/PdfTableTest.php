<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Export;

use App\Shared\Application\Export\CsvWriter;
use App\Shared\Application\Export\PdfTable;
use PHPUnit\Framework\TestCase;

/** FR-RPT-003: a report as a PDF says what the CSV of the same report says, runs over as many pages as it needs, and is a well-formed file. */
final class PdfTableTest extends TestCase
{
    /** The text drawn on all pages, from the compressed page streams. */
    private static function drawn(string $pdf): string
    {
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $streams);

        return implode("\n", array_map(static fn (string $s): string => (string) gzuncompress($s), $streams[1]));
    }

    public function test_the_file_is_well_formed_and_its_cross_reference_points_at_its_objects(): void
    {
        $pdf = PdfTable::build('Flash report', ['Made 2026-10-03'], ['Day', 'Total'], [['2026-10-01', '1000'], ['2026-10-02', '2000']]);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        preg_match('/startxref\n(\d+)\n/', $pdf, $start);
        self::assertSame('xref', substr($pdf, (int) $start[1], 4));
        preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets);

        foreach ($offsets[1] as $i => $offset) {
            self::assertStringStartsWith(($i + 1).' 0 obj', substr($pdf, (int) $offset, 12), 'object '.($i + 1));
        }

        self::assertCount(substr_count($pdf, 'endobj'), $offsets[1]);
        self::assertStringContainsString('/Type /Catalog', $pdf);
        self::assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $pdf);
    }

    public function test_the_cells_are_what_the_csv_holds_with_special_characters_escaped_and_formula_guards_removed(): void
    {
        $csv = CsvWriter::build(['Name', 'Note', 'Amount'], [['Budi (Ñandú)', '=SUM(A1) back\\slash', '1250000'], ['Siti', '+62 812 3456', '-5']]);
        $drawn = self::drawn(PdfTable::fromCsv('Guests', ['2026-10-01 to 2026-10-03'], $csv));

        self::assertStringContainsString('(Guests)', $drawn);
        self::assertStringContainsString('(2026-10-01 to 2026-10-03)', $drawn);
        self::assertStringContainsString('(Budi \\(\\321and\\372\\))', str_replace(["\xD1", "\xFA"], ['\\321', '\\372'], $drawn));
        self::assertStringContainsString('(=SUM\\(A1\\) back\\\\slash)', $drawn, 'parentheses and the backslash are escaped, and the apostrophe the CSV put in front of a formula is gone');
        self::assertStringContainsString('(+62 812 3456)', $drawn);
        self::assertStringNotContainsString("('=", $drawn);
    }

    public function test_a_long_table_runs_over_pages_with_the_header_on_each_and_the_page_numbers(): void
    {
        $rows = [];

        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ['RSV-'.sprintf('%06d', $i), (string) ($i * 100)];
        }

        $pdf = PdfTable::build('Reservations', [], ['Number', 'Total'], $rows);
        preg_match('/\/Count (\d+)/', $pdf, $count);
        self::assertGreaterThan(2, (int) $count[1]);
        self::assertSame((int) $count[1], substr_count($pdf, '/Type /Page '));
        $drawn = self::drawn($pdf);
        self::assertSame((int) $count[1], substr_count($drawn, '(Number)'), 'the header is repeated on every page');
        self::assertStringContainsString(sprintf('(%d / %d)', $count[1], $count[1]), $drawn);
        self::assertSame(120, substr_count($drawn, '(RSV-'));
    }

    public function test_a_cell_wider_than_its_column_is_cut_not_run_into_the_next(): void
    {
        $pdf = PdfTable::build('Wide', [], ['A', 'B'], [[str_repeat('Long text ', 60), 'x']]);

        self::assertStringContainsString('…', (string) mb_convert_encoding(self::drawn($pdf), 'UTF-8', 'Windows-1252'));
        self::assertStringNotContainsString(str_repeat('Long text ', 20), self::drawn($pdf));
    }
}
