<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Export\CsvWriter;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Finance data for a spreadsheet or for the accounting software the property uses (FR-FIN-034). Each data set is a CSV with stable column names, one row
 * per booked record dated in the range, money in major units with two decimals and dates as year-month-day. Cells are made safe for spreadsheets, a range
 * is at most a year and a file at most 50,000 rows, and each export is written to the audit trail.
 */
final readonly class FinanceExportService
{
    public const DATASETS = ['revenue', 'payments_received', 'payables', 'supplier_payments', 'receivables', 'receipts', 'petty_vouchers', 'corrections', 'exceptions'];

    public const MAX_ROWS = 50_000;

    public const MAX_RANGE_DAYS = 366;

    public function __construct(private FinanceExportQueries $queries, private FinanceAccess $access, private BusinessDateProvider $businessDate, private AuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, FinanceAccess::EXPORT, 'This person may not export finance data.');
        $today = $this->businessDate->current($property)->toString();

        return ['datasets' => self::DATASETS, 'today' => $today, 'from' => substr($today, 0, 8).'01', 'max_rows' => self::MAX_ROWS];
    }

    /** @return array{filename: string, contents: string} */
    public function export(PropertyId $property, string $actorId, string $dataset, ?string $from, ?string $to): array
    {
        $this->access->require($property, $actorId, FinanceAccess::EXPORT, 'This person may not export finance data.');

        if (! in_array($dataset, self::DATASETS, true)) {
            throw Refusal::notFound('Unknown data set.');
        }

        $today = $this->businessDate->current($property)->toString();
        $to = $to === null || $to === '' ? $today : $this->date($to, 'to');
        $from = $from === null || $from === '' ? substr($to, 0, 8).'01' : $this->date($from, 'from');

        if ($from > $to) {
            throw Refusal::invalid('The start of the range is after its end.', ['from']);
        }

        if ((int) (new DateTimeImmutable($from, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($to, new DateTimeZone('UTC')))->days >= self::MAX_RANGE_DAYS) {
            throw Refusal::invalid('Choose a range of at most a year.', ['to']);
        }

        $data = $this->queries->dataset($property, $dataset, $from, $to, self::MAX_ROWS);

        if (count($data['rows']) > self::MAX_ROWS) {
            throw Refusal::invalid('This range has more than '.self::MAX_ROWS.' rows. Choose a shorter one.', ['to']);
        }

        $rows = array_map(fn (array $row): array => $this->format($row, $data['money']), $data['rows']);
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'finance.exported', 'finance_export', $dataset, null, ['dataset' => $dataset, 'from' => $from, 'to' => $to, 'rows' => count($rows)]));

        return ['filename' => sprintf('finance-%s-%s-%s.csv', str_replace('_', '-', $dataset), $from, $to), 'contents' => CsvWriter::build($data['header'], $rows)];
    }

    /** @param list<scalar|null> $row @param list<int> $money @return list<scalar|null> */
    private function format(array $row, array $money): array
    {
        foreach ($money as $i) {
            $minor = (int) $row[$i];
            $row[$i] = ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
        }

        return $row;
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }
}
