<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Shared\Application\Import\CsvRows;
use PHPUnit\Framework\TestCase;

final class CsvRowsTest extends TestCase
{
    public function test_it_reads_rows_with_their_lines_whatever_separator_a_spreadsheet_used(): void
    {
        $semicolon = CsvRows::parse("\xEF\xBB\xBFcode;name\r\nA1;Alpha\r\n\r\nB2;\"Beta; Co\"\r\n", ['code', 'name']);

        $this->assertSame([], $semicolon['errors']);
        $this->assertSame([['line' => 2, 'values' => ['code' => 'A1', 'name' => 'Alpha']], ['line' => 4, 'values' => ['code' => 'B2', 'name' => 'Beta; Co']]], $semicolon['rows']);
    }

    public function test_a_missing_column_or_empty_cell_refuses_the_file_and_names_the_place(): void
    {
        $this->assertSame('missing_column', CsvRows::parse("code\nA\n", ['code', 'name'])['errors'][0]['code']);

        $errors = CsvRows::parse("code,name\nA,\nB,Bee\n", ['code', 'name'])['errors'];
        $this->assertSame([['line' => 2, 'code' => 'empty_cell', 'message' => 'name']], $errors);
        $this->assertSame('empty_file', CsvRows::parse("  \n", ['code'])['errors'][0]['code']);
        $this->assertSame('no_rows', CsvRows::parse("code\n", ['code'])['errors'][0]['code']);
    }

    public function test_too_many_rows_are_refused(): void
    {
        $csv = "code\n".implode("\n", array_fill(0, CsvRows::MAX_ROWS + 1, 'X'));

        $this->assertSame('too_many', CsvRows::parse($csv, ['code'])['errors'][0]['code']);
    }
}
