<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Finance;

use App\Modules\Finance\Application\BankStatementParser;
use PHPUnit\Framework\TestCase;

/** The CSV a bank gives, read the way Indonesian internet banking writes it. */
final class BankStatementParserTest extends TestCase
{
    public function test_amounts_in_both_writings_and_with_the_signs_banks_use(): void
    {
        foreach ([['1,234,567.89', 123_456_789], ['1.234.567,89', 123_456_789], ['1234567', 123_456_700], ['1.500', 150_000], ['1,500', 150_000], ['500.000', 50_000_000], ['0,50', 50], ['Rp 2.000.000,00', 200_000_000]] as [$text, $minor]) {
            self::assertSame($minor, BankStatementParser::amount($text)['minor'] ?? null, $text);
        }

        self::assertSame(-5_000_000, BankStatementParser::amount('-50.000,00')['minor']);
        self::assertSame(-5_000_000, BankStatementParser::amount('(50,000.00)')['minor']);
        self::assertSame(['minor' => 125_000_000, 'sign' => 'in'], BankStatementParser::amount('1,250,000.00 CR'));
        self::assertSame(['minor' => 125_000_000, 'sign' => 'out'], BankStatementParser::amount('1.250.000,00 DB'));
        self::assertNull(BankStatementParser::amount('abc'));
        self::assertNull(BankStatementParser::amount(''));
    }

    public function test_dates_year_first_or_day_first(): void
    {
        self::assertSame('2026-10-03', BankStatementParser::date('2026-10-03'));
        self::assertSame('2026-10-03', BankStatementParser::date('03/10/2026'));
        self::assertSame('2026-10-03', BankStatementParser::date('3-10-26'));
        self::assertSame('2026-10-03', BankStatementParser::date('03.10.2026 14:22'));
        self::assertNull(BankStatementParser::date('31/02/2026'));
        self::assertNull(BankStatementParser::date('October 3'));
    }

    public function test_a_file_with_debit_and_credit_columns_in_indonesian(): void
    {
        $csv = "Tanggal;Keterangan;Referensi;Debit;Kredit;Saldo\n03/10/2026;TRF KE PT JAYA ABADI;TRX1;1.500.000,00;;10.000.000,00\n04/10/2026;KREDIT DARI AGODA;TRX2;;2.000.000,00;12.000.000,00\n";
        $r = BankStatementParser::parse($csv);

        self::assertSame([], $r['errors']);
        self::assertSame([['2026-10-03', 'out', 150_000_000, 'TRX1', 1_000_000_000], ['2026-10-04', 'in', 200_000_000, 'TRX2', 1_200_000_000]], array_map(fn (array $l): array => [$l['date'], $l['direction'], $l['amount_minor'], $l['reference'], $l['balance_minor']], $r['lines']));
    }

    public function test_a_file_with_one_signed_amount_column_and_cr_db(): void
    {
        $signed = BankStatementParser::parse("date,description,amount\n2026-10-03,Pay supplier,-1500000\n2026-10-04,Guest transfer,2000000\n");
        self::assertSame(['out', 'in'], array_column($signed['lines'], 'direction'));

        $suffix = BankStatementParser::parse("Tanggal,Keterangan,Jumlah\n03/10/2026,BIAYA ADM,\"15,000.00 DB\"\n04/10/2026,SETORAN,\"2,000,000.00 CR\"\n");
        self::assertSame(['out', 'in'], array_column($suffix['lines'], 'direction'));
        self::assertSame([1_500_000, 200_000_000], array_column($suffix['lines'], 'amount_minor'));
    }

    public function test_a_bad_file_is_refused_with_the_line_and_nothing_is_read(): void
    {
        self::assertSame('empty_file', BankStatementParser::parse('')['errors'][0]['code']);
        self::assertSame(['missing_column', 'missing_column', 'missing_column'], array_column(BankStatementParser::parse("a,b,c\n1,2,3\n")['errors'], 'code'));
        $bad = BankStatementParser::parse("date,description,amount\n2026-10-03,ok,1000\n31/02/2026,bad,1000\n2026-10-05,no money,\n");
        self::assertSame([[3, 'bad_date'], [4, 'bad_amount']], array_map(fn (array $e): array => [$e['line'], $e['code']], $bad['errors']));
        self::assertSame([], $bad['lines'], 'one bad line refuses the whole file');
    }

    public function test_lines_above_the_table_and_totals_under_it_are_skipped(): void
    {
        $csv = "Rekening: 1234567890\nPeriode: 01/10/2026 - 31/10/2026\n\nTanggal;Keterangan;Debit;Kredit;Saldo\n05/10/2026;TRF MASUK;;1.500.000,00;1.500.000,00\n06/10/2026;BIAYA ADM;15.000,00;;1.485.000,00\n;Total;15.000,00;1.500.000,00;\n";
        $read = BankStatementParser::parse($csv);

        self::assertSame([], $read['errors']);
        self::assertSame(['2026-10-05', 'in', 150_000_000], [$read['lines'][0]['date'], $read['lines'][0]['direction'], $read['lines'][0]['amount_minor']]);
        self::assertSame(['2026-10-06', 'out', 1_500_000], [$read['lines'][1]['date'], $read['lines'][1]['direction'], $read['lines'][1]['amount_minor']]);
        self::assertCount(2, $read['lines']);
        self::assertSame(5, $read['lines'][0]['line'], 'a line is named by its place in the file');
    }

    public function test_a_file_without_any_header_is_still_refused_by_its_first_line(): void
    {
        $read = BankStatementParser::parse("Hello\nWorld\n");

        self::assertSame([], $read['lines']);
        self::assertSame('missing_column', $read['errors'][0]['code']);
        self::assertSame(1, $read['errors'][0]['line']);
    }
}
