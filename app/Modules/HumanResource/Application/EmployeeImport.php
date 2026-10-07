<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Import\BatchImport;
use App\Shared\Application\Import\CsvRows;
use App\Shared\Domain\Tenancy\PropertyId;

/** Opens the staff list from a CSV file (first-time setup): every row goes through the same `EmployeeService::create` a person uses on screen, all or nothing. */
final readonly class EmployeeImport
{
    public const TEMPLATE = "full_name,department,position,joined_on,contract_type,contract_end_on,phone,email\nRina Putri,front_office,Receptionist,2026-01-05,permanent,,081234567890,rina@example.com\nBudi Santoso,housekeeping,Room attendant,2026-02-01,contract,2027-01-31,,\n";

    public function __construct(private EmployeeService $employees, private BatchImport $batch) {}

    /** @return array{status: string, dry_run: bool, count: int, errors: list<array{line: int, code: string, message: string}>} */
    public function run(PropertyId $property, string $actorId, string $csv, bool $dryRun): array
    {
        $parsed = CsvRows::parse($csv, ['full_name', 'department', 'position', 'joined_on', 'contract_type'], ['contract_end_on', 'phone', 'email']);

        return $this->batch->run($parsed, fn (array $row) => $this->employees->create($property, $actorId, $row), $dryRun);
    }
}
