<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The booked facts the accounting export turns into journal entries. Each list holds the records dated in the range, oldest first, at most `$limit + 1` of them; money is in minor units.
 * A revenue day and a correction are `{date, number, revenue: [{key, base, service, tax}], payments: [{method, net}]}` (`key` is the outlet code, else the source).
 */
interface JournalSources
{
    /** @return list<array{date: string, number: string, revenue: list<array{key: string, base: int, service: int, tax: int}>, payments: list<array{method: string, net: int}>}> */
    public function revenueDays(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, revenue: list<array{key: string, base: int, service: int, tax: int}>, payments: list<array{method: string, net: int}>}> */
    public function corrections(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, supplier: string, document: string, amount: int, tax: int, account_code: string|null, account_name: string|null}> */
    public function payables(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, supplier: string, document: string, amount: int}> */
    public function supplierCredits(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, supplier: string, method: string, amount: int}> a reversal has a negative amount */
    public function supplierPayments(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, payee: string, account_code: string, account_name: string, amount: int}> vouchers that were not voided */
    public function pettyVouchers(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, customer: string, amount: int}> */
    public function receivables(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, customer: string, method: string, amount: int}> a reversal has a negative amount */
    public function receipts(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, amount: int, method: string, kind: string}> gift cards and vouchers sold; `number` is the last four characters of the code */
    public function giftCardIssues(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, amount: int, method: string, disposition: string}> what was left on voided cards, paid back (by the method the card was bought with) or written off */
    public function giftCardVoids(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, partner: string, amount: int}> commissions accrued at check-out (voided ones are left out) */
    public function commissionAccruals(PropertyId $property, string $from, string $to, int $limit): array;

    /** @return list<array{date: string, number: string, partner: string, amount: int, method: string}> */
    public function commissionStatements(PropertyId $property, string $from, string $to, int $limit): array;
}
