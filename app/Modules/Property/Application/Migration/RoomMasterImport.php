<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Migration;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use RuntimeException;

/**
 * Opening the room master from files (cutover, docs/TASK/DATA-MIGRATION-CUTOVER.md): room types, then rooms. Every row goes through
 * the same service a person uses, so the same rules, permission and audit entries apply; a dry run is that exact path rolled back,
 * so it can not pass a row the real run would refuse. A run is all or nothing, reports every bad row with its file and line, and
 * the control totals (counts and a file fingerprint) are compared with the figures the owners signed off. The same files can not
 * be applied twice.
 */
final readonly class RoomMasterImport
{
    public const KIND = 'room_master';

    public const MAX_ROWS = 2000;

    public const TYPE_HEADER = ['code', 'name', 'max_adults', 'max_children', 'sort_order'];

    public const ROOM_HEADER = ['number', 'type_code', 'floor'];

    public function __construct(
        private RoomCatalogService $catalog,
        private RoomCatalogReader $reader,
        private ImportBatchRepository $batches,
        private TransactionRunner $transactions,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{batch_id: string, status: string, dry_run: bool, checksum: string, errors: list<array{file: string, line: int, message: string}>, totals: array<string, mixed>, expectations: array<string, array{expected: int, actual: int, ok: bool}>}
     */
    public function run(PropertyId $property, string $actorId, string $typesCsv, string $roomsCsv, bool $dryRun, ?int $expectedTypes, ?int $expectedRooms): array
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $checksum = hash('sha256', hash('sha256', $typesCsv).hash('sha256', $roomsCsv));
        $batchId = $this->ids->next();
        $errors = [];
        $fatal = false;
        $types = self::parse('room_types.csv', $typesCsv, self::TYPE_HEADER, $errors, $fatal);
        $rooms = self::parse('rooms.csv', $roomsCsv, self::ROOM_HEADER, $errors, $fatal);

        if (! $dryRun && $this->batches->wasApplied($property, self::KIND, $checksum)) {
            $errors[] = ['file' => '-', 'line' => 0, 'message' => 'These files were already applied to this property.'];
            $fatal = true;
        }

        $totals = ['types' => 0, 'rooms' => 0, 'rooms_per_type' => []];
        $report = [];

        try {
            $this->transactions->run(function () use ($property, $actorId, $types, $rooms, $batchId, $dryRun, $expectedTypes, $expectedRooms, $checksum, $fatal, &$errors, &$totals, &$report): void {
                $reason = 'Cutover import '.$batchId;
                $byCode = [];

                // A file that cannot be read at all stops the run; a bad row does not, so every problem is reported in one pass.
                if (! $fatal) {
                    foreach ($types as $row) {
                        try {
                            $type = $this->catalog->createType($property, $actorId, $row['values'][0], $row['values'][1], null, (int) $row['values'][2], (int) $row['values'][3], (int) $row['values'][4], $reason);
                            $byCode[strtoupper($row['values'][0])] = $type->id;
                            $totals['types']++;
                        } catch (Refusal $e) {
                            $errors[] = ['file' => 'room_types.csv', 'line' => $row['line'], 'message' => $e->getMessage()];
                        }
                    }

                    foreach ($this->reader->activeTypes($property) as $existing) {
                        $byCode[strtoupper($existing->code)] ??= $existing->id;
                    }

                    foreach ($rooms as $row) {
                        $typeId = $byCode[strtoupper($row['values'][1])] ?? null;

                        if ($typeId === null) {
                            $errors[] = ['file' => 'rooms.csv', 'line' => $row['line'], 'message' => 'The room type code "'.$row['values'][1].'" is not in the types file or the property.'];

                            continue;
                        }

                        try {
                            $this->catalog->createRoom($property, $actorId, $row['values'][0], $typeId, $row['values'][2] === '' ? null : $row['values'][2], $reason);
                            $totals['rooms']++;
                            $totals['rooms_per_type'][strtoupper($row['values'][1])] = ($totals['rooms_per_type'][strtoupper($row['values'][1])] ?? 0) + 1;
                        } catch (Refusal $e) {
                            $errors[] = ['file' => 'rooms.csv', 'line' => $row['line'], 'message' => $e->getMessage()];
                        }
                    }
                }

                ksort($totals['rooms_per_type']);
                $report = $this->expectations($totals, $expectedTypes, $expectedRooms);

                // Nothing stays unless the run is real, clean and matches the figures that were signed off.
                if ($dryRun || $errors !== [] || in_array(false, array_column($report, 'ok'), true)) {
                    throw new RuntimeException('rollback');
                }

                $this->batches->add($property, $batchId, self::KIND, $checksum, 'applied', 0, ['totals' => $totals, 'expectations' => $report], $actorId, $this->clock->nowUtc());
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'rollback') {
                throw $e;
            }
        }

        $applied = $errors === [] && ! $dryRun && ! in_array(false, array_column($report, 'ok'), true);
        $status = $applied ? 'applied' : ($dryRun && $errors === [] && ! in_array(false, array_column($report, 'ok'), true) ? 'validated' : 'rejected');

        if (! $applied) {
            // Dry runs and refused runs leave evidence too, outside the rolled-back work.
            $this->batches->add($property, $batchId, self::KIND, $checksum, $status, count($errors), ['totals' => $totals, 'expectations' => $report, 'errors' => array_slice($errors, 0, 200)], $actorId, $this->clock->nowUtc());
        }

        return ['batch_id' => $batchId, 'status' => $status, 'dry_run' => $dryRun, 'checksum' => $checksum, 'errors' => $errors, 'totals' => $totals, 'expectations' => $report];
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, array{expected: int, actual: int, ok: bool}>
     */
    private function expectations(array $totals, ?int $types, ?int $rooms): array
    {
        $result = [];

        foreach (['types' => $types, 'rooms' => $rooms] as $name => $expected) {
            if ($expected !== null) {
                $result[$name] = ['expected' => $expected, 'actual' => (int) $totals[$name], 'ok' => $expected === (int) $totals[$name]];
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $header
     * @param  list<array{file: string, line: int, message: string}>  $errors
     * @return list<array{line: int, values: list<string>}> the rows that are well formed; `$fatal` is set when the file is unusable
     */
    private static function parse(string $file, string $csv, array $header, array &$errors, bool &$fatal): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $rows = [];
        $first = true;

        foreach ($lines as $index => $text) {
            if (trim($text) === '') {
                continue;
            }

            $cells = array_map('trim', str_getcsv($text, ',', '"', ''));

            if ($first) {
                $first = false;

                if ($cells !== $header) {
                    $errors[] = ['file' => $file, 'line' => $index + 1, 'message' => 'The header must be exactly: '.implode(',', $header)];
                    $fatal = true;

                    return [];
                }

                continue;
            }

            if (count($cells) !== count($header)) {
                $errors[] = ['file' => $file, 'line' => $index + 1, 'message' => 'Expected '.count($header).' columns, found '.count($cells).'.'];

                continue;
            }

            $rows[] = ['line' => $index + 1, 'values' => $cells];
        }

        if ($first) {
            $errors[] = ['file' => $file, 'line' => 0, 'message' => 'The file is empty.'];
            $fatal = true;
        }

        if (count($rows) > self::MAX_ROWS) {
            $errors[] = ['file' => $file, 'line' => 0, 'message' => 'At most '.self::MAX_ROWS.' rows per file.'];
            $fatal = true;

            return [];
        }

        $valid = [];

        foreach ($rows as $row) {
            $bad = false;

            if ($file === 'room_types.csv') {
                foreach ([2, 3, 4] as $numeric) {
                    if (preg_match('/^\d{1,5}$/D', $row['values'][$numeric]) !== 1) {
                        $errors[] = ['file' => $file, 'line' => $row['line'], 'message' => 'Column '.$header[$numeric].' must be a whole number.'];
                        $bad = true;
                    }
                }
            }

            if (! $bad) {
                $valid[] = $row;
            }
        }

        return $valid;
    }
}
