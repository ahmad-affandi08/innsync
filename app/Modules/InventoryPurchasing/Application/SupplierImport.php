<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Import\BatchImport;
use App\Shared\Application\Import\CsvRows;
use App\Shared\Domain\Tenancy\PropertyId;

/** Opens the supplier list from a CSV file: every row goes through the same `SupplierService::create` a person uses on screen, all or nothing. */
final readonly class SupplierImport
{
    public const TEMPLATE = "code,name,payment_terms_days,contact_name,phone,email,address,tax_id,note\nSUP-001,Toko Sembako Jaya,14,Pak Andi,081234567890,andi@example.com,Jl. Merdeka 1,,\nSUP-002,Kebun Segar,7,,,,,,\n";

    public function __construct(private SupplierService $suppliers, private BatchImport $batch) {}

    /** @return array{status: string, dry_run: bool, count: int, errors: list<array{line: int, code: string, message: string}>} */
    public function run(PropertyId $property, string $actorId, string $csv, bool $dryRun): array
    {
        $parsed = CsvRows::parse($csv, ['code', 'name', 'payment_terms_days'], ['contact_name', 'phone', 'email', 'address', 'tax_id', 'note']);

        return $this->batch->run($parsed, function (array $row) use ($property, $actorId): void {
            if (preg_match('/^\d{1,3}$/', $row['payment_terms_days']) !== 1 || (int) $row['payment_terms_days'] > 180) {
                throw Refusal::invalid('Payment terms are a number of days from 0 to 180.', ['payment_terms_days']);
            }

            $this->suppliers->create($property, $actorId, $row['code'], $row['name'], [...$row, 'payment_terms_days' => (int) $row['payment_terms_days']]);
        }, $dryRun);
    }
}
