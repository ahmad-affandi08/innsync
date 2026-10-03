<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** What the management reports read: the booked revenue, the costs that were recorded, the stock that was consumed and the money that moved. Dates are business dates. */
interface ManagementReportQueries
{
    /** @return list<array{outlet_code: string, outlet_name: string|null, base_minor: int, service_charge_minor: int, tax_minor: int}> */
    public function revenueByOutlet(PropertyId $property, string $from, string $to): array;

    /** Booked days of the range that finance has not verified yet. */
    public function unverifiedDays(PropertyId $property, string $from, string $to): int;

    /**
     * Supplier invoices of the range (by invoice date) without the tax, by expense account; `account_id` is null for those not classified yet.
     *
     * @return list<array{account_id: string|null, code: string|null, name: string|null, department: string|null, category: string|null, amount_minor: int}>
     */
    public function payableCosts(PropertyId $property, string $from, string $to): array;

    /** @return list<array{account_id: string, code: string, name: string, department: string, category: string, amount_minor: int}> petty cash vouchers that were not voided, by expense account */
    public function pettyCosts(PropertyId $property, string $from, string $to): array;

    /** @return array<string, int> department to the value of stock issued, written off or adjusted out, less what was adjusted in */
    public function stockConsumption(PropertyId $property, string $from, string $to): array;

    /** @return array<string, string> outlet code to department */
    public function outletMap(PropertyId $property): array;

    public function saveOutletMapping(PropertyId $property, string $outletCode, string $department, string $by, DateTimeImmutable $at): void;

    /** @return list<array{method: string, received_minor: int, paid_back_minor: int}> what guests paid and was paid back, by method, from the booked days */
    public function guestPayments(PropertyId $property, string $from, string $to): array;

    /** @return list<array{method: string, amount_minor: int}> receipts against receivables made by hand (those billed from a folio are in the guest payments) */
    public function manualReceipts(PropertyId $property, string $from, string $to): array;

    /** @return list<array{method: string, amount_minor: int}> supplier payments that were paid, by method, by the date paid */
    public function supplierPayments(PropertyId $property, string $from, string $to): array;

    /** Petty cash vouchers that were not voided, by voucher date. */
    public function pettySpent(PropertyId $property, string $from, string $to): int;

    /** @return array{cash_opening_minor: int, bank_opening_minor: int, opening_date: string, lock_version: int}|null */
    public function cashSettings(PropertyId $property): ?array;

    /** @return bool false when the settings changed meanwhile */
    public function saveCashSettings(PropertyId $property, int $cash, int $bank, string $openingDate, ?int $expectedLockVersion, string $by, DateTimeImmutable $at): bool;
}
