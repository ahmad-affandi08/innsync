<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Export\CsvWriter;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\DateMath;

/**
 * The regional tax and the service charge (FR-FIN-020 to -025). The books give, for each month and outlet, the base the tax was charged on, the service charge and the tax, as they were posted with the scheme in force on the day
 * (a scheme has a day from which it holds and is never edited, and every posting keeps the rates and the rounding it was made with, so a change of rates never touches what was posted before). Each month has a day it is to be reported by (a
 * day of the next month the owner sets; the 15th until then), and goes from collecting, to waiting to be reported, to reported (with the amounts the books showed that day) and deposited (with the date, the amount and a reference).
 * What is kept apart from the taxed revenue is shown with it: revenue booked without tax, complimentary items, other discounts, lines voided, bills cancelled and charges reversed. A recap file of a month is ready to hand to the tax office.
 */
final readonly class TaxService
{
    public const BASELINE_REPORT_DAY = 15;

    public function __construct(
        private TaxStore $store,
        private TaxQueries $queries,
        private ChargeSchemeService $schemes,
        private PropertyCurrencyReader $currencies,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, int $months = 12): array
    {
        $this->access->require($property, $actorId, FinanceAccess::TAX_VIEW, 'This person may not see the tax.');
        $today = $this->businessDate->current($property)->toString();
        $settings = $this->settings($property);
        $first = DateMath::format('Y-m-01', substr($today, 0, 8).'01 -'.($months - 1).' months');
        $rows = $this->queries->byOutlet($property, $first, $today);
        $aside = $this->queries->setAside($property, $first, $today);
        $filings = $this->store->filings($property);
        $manage = $this->access->may($property, $actorId, FinanceAccess::TAX_MANAGE);
        $out = [];

        for ($d = substr($today, 0, 7); $d >= substr($first, 0, 7); $d = DateMath::format('Y-m', $d.'-01 -1 month')) {
            $outlets = array_values(array_filter($rows, static fn (array $r): bool => $r['period'] === $d));
            $filing = $filings[$d] ?? null;
            $end = DateMath::format('Y-m-t', $d.'-01');
            $over = $today > $end;
            $due = substr(DateMath::format('Y-m-d', $end.' +1 day'), 0, 8).str_pad((string) $settings['report_day'], 2, '0', STR_PAD_LEFT);
            $status = $filing === null ? ($over ? 'to_report' : 'collecting') : ($filing['deposited_on'] === null ? 'reported' : 'deposited');
            $totals = ['base_minor' => array_sum(array_column($outlets, 'base_minor')), 'service_charge_minor' => array_sum(array_column($outlets, 'service_charge_minor')), 'tax_minor' => array_sum(array_column($outlets, 'tax_minor')), 'total_minor' => array_sum(array_column($outlets, 'total_minor'))];

            $out[] = [
                'period' => $d, 'outlets' => $outlets, 'totals' => $totals, 'set_aside' => $aside[$d] ?? ['non_taxed_minor' => 0, 'complimentary_minor' => 0, 'discounts_minor' => 0, 'voided_minor' => 0, 'cancelled_minor' => 0, 'reversed_minor' => 0],
                'status' => $status, 'due_date' => $due, 'overdue' => $over && $status !== 'deposited' && $today > $due, 'filing' => $filing === null ? null : self::filing($filing), 'may' => ['report' => $manage && $status === 'to_report', 'deposit' => $manage && $status === 'reported'],
            ];
        }

        $all = $this->schemes->all($property);
        $date = BusinessDate::fromString($today);
        $inForce = [];

        foreach ($all['scopes'] as $scope => $history) {
            $now = $this->schemes->schemeFor($property, $scope, $date);
            $inForce[] = ['scope' => $scope, 'current' => $now === null ? null : ['effective_from' => $now->effectiveFrom->toString(), 'service_charge_bp' => $now->serviceCharge->basisPoints, 'tax_bp' => $now->tax->basisPoints, 'tax_on_service_charge' => $now->taxOnServiceCharge],
                'history' => array_map(static fn ($c): array => ['effective_from' => $c->effectiveFrom->toString(), 'service_charge_bp' => $c->serviceCharge->basisPoints, 'tax_bp' => $c->tax->basisPoints, 'tax_on_service_charge' => $c->taxOnServiceCharge], $history)];
        }

        return ['currency' => $this->currencies->currencyOf($property), 'today' => $today, 'settings' => $settings, 'may' => ['manage' => $manage], 'months' => $out, 'schemes' => $inForce, 'rounding' => ['increment_minor' => $all['rounding_increment_minor'], 'mode' => $all['rounding_mode']]];
    }

    /** @return array{report_day: int, is_baseline: bool, lock_version: int|null} */
    public function settings(PropertyId $property): array
    {
        $r = $this->store->settings($property);

        return ['report_day' => (int) ($r['report_day'] ?? self::BASELINE_REPORT_DAY), 'is_baseline' => $r === null, 'lock_version' => $r === null ? null : (int) $r['lock_version']];
    }

    /** @return array<string, mixed> */
    public function saveSettings(PropertyId $property, string $actorId, int $reportDay, ?int $lock): array
    {
        $this->access->require($property, $actorId, FinanceAccess::TAX_MANAGE, 'This person may not set the tax deadlines.');

        if ($reportDay < 1 || $reportDay > 28) {
            throw Refusal::invalid('Give a day of the month from 1 to 28.', ['report_day']);
        }

        $before = $this->settings($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $reportDay, $lock, $before): void {
            if ($before['lock_version'] !== $lock || ! $this->store->saveSettings($property, $reportDay, $before['lock_version'], $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This setting changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'tax_settings.changed', 'tax_settings', $property->toString(), ['report_day' => $before['report_day']], ['report_day' => $reportDay]));
        });

        return $this->overview($property, $actorId);
    }

    /** The month was reported to the tax office, with the amounts the books show now. @return array<string, mixed> */
    public function report(PropertyId $property, string $actorId, string $period, ?string $reference): array
    {
        $this->access->require($property, $actorId, FinanceAccess::TAX_MANAGE, 'This person may not record the reporting of the tax.');
        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);

        if ($reference !== null && mb_strlen($reference) > 80) {
            throw Refusal::invalid('The reference is at most 80 characters.', ['reference']);
        }

        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();
        $this->month($period);

        if ($today <= DateMath::format('Y-m-t', $period.'-01')) {
            throw Refusal::stateConflict('The month is not over yet.');
        }

        $this->transactions->run(function () use ($property, $actor, $period, $reference, $today): void {
            $rows = $this->queries->byOutlet($property, $period.'-01', DateMath::format('Y-m-t', $period.'-01'));
            $amounts = ['base_minor' => array_sum(array_column($rows, 'base_minor')), 'service_charge_minor' => array_sum(array_column($rows, 'service_charge_minor')), 'tax_minor' => array_sum(array_column($rows, 'tax_minor'))];

            if (! $this->store->addFiling($property, ['id' => $this->ids->next(), 'period' => $period, ...$amounts, 'reported_on' => $today, 'report_reference' => $reference, 'reported_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This month was reported already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'tax.reported', 'tax_month', $period, null, ['period' => $period, ...$amounts, 'reference' => $reference]));
            $this->outbox->publish(new OutboxEvent($property, 'finance.tax.reported', $this->ids->next(), 1, ['period' => $period, ...$amounts]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function deposit(PropertyId $property, string $actorId, string $period, int $amountMinor, string $depositedOn, string $reference, int $lock): array
    {
        $this->access->require($property, $actorId, FinanceAccess::TAX_MANAGE, 'This person may not record the deposit of the tax.');
        $reference = trim($reference);
        $this->month($period);
        $today = $this->businessDate->current($property)->toString();

        if ($reference === '' || mb_strlen($reference) > 80) {
            throw Refusal::invalid('Give the reference of the deposit, at most 80 characters.', ['reference']);
        }

        if ($amountMinor < 0 || $amountMinor > 9_000_000_000_000) {
            throw Refusal::invalid('Give the amount deposited, 0 or more.', ['amount_minor']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $depositedOn) !== 1 || $depositedOn > $today) {
            throw Refusal::invalid('Give the day of the deposit, today or earlier.', ['deposited_on']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $period, $amountMinor, $depositedOn, $reference, $lock): void {
            $filing = $this->store->filings($property)[$period] ?? throw Refusal::stateConflict('Record that the month was reported first.');

            if ($filing['deposited_on'] !== null) {
                throw Refusal::stateConflict('This month was deposited already.');
            }

            if ($depositedOn < substr((string) $filing['reported_on'], 0, 10)) {
                throw Refusal::invalid('The deposit is not before the day the month was reported.', ['deposited_on']);
            }

            if (! $this->store->updateFiling($property, $period, $lock, ['deposited_on' => $depositedOn, 'deposited_minor' => $amountMinor, 'deposit_reference' => $reference, 'deposited_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This month changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'tax.deposited', 'tax_month', $period, null, ['period' => $period, 'amount_minor' => $amountMinor, 'tax_minor' => (int) $filing['tax_minor'], 'difference_minor' => $amountMinor - (int) $filing['tax_minor'], 'reference' => $reference]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array{filename: string, contents: string} */
    public function recap(PropertyId $property, string $actorId, string $period): array
    {
        $this->access->require($property, $actorId, FinanceAccess::TAX_VIEW, 'This person may not take the tax recap.');
        $this->month($period);
        $from = $period.'-01';
        $to = DateMath::format('Y-m-t', $from);
        $rows = $this->queries->byOutlet($property, $from, $to);
        $aside = $this->queries->setAside($property, $from, $to)[$period] ?? [];
        $money = static fn (int $minor): string => ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
        $lines = array_map(static fn (array $r): array => [$period, 'taxed', $r['outlet'], $money($r['base_minor']), $money($r['service_charge_minor']), $money($r['tax_minor']), $money($r['total_minor'])], $rows);
        $lines[] = [$period, 'total', '', $money(array_sum(array_column($rows, 'base_minor'))), $money(array_sum(array_column($rows, 'service_charge_minor'))), $money(array_sum(array_column($rows, 'tax_minor'))), $money(array_sum(array_column($rows, 'total_minor')))];

        foreach (['non_taxed_minor' => 'not_taxed', 'complimentary_minor' => 'complimentary', 'discounts_minor' => 'discounts', 'voided_minor' => 'voided_lines', 'cancelled_minor' => 'cancelled_bills', 'reversed_minor' => 'reversed_charges'] as $key => $label) {
            $lines[] = [$period, 'set_aside', $label, $money((int) ($aside[$key] ?? 0)), '', '', ''];
        }

        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'tax.recap_exported', 'tax_month', $period, null, ['period' => $period, 'rows' => count($rows)]));

        return ['filename' => 'tax-recap-'.$period.'.csv', 'contents' => CsvWriter::build(['period', 'section', 'outlet_or_item', 'base', 'service_charge', 'tax', 'total'], $lines)];
    }

    private function month(string $period): void
    {
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw Refusal::invalid('Give the month as year-month.', ['period']);
        }
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private static function filing(array $f): array
    {
        return [
            'base_minor' => (int) $f['base_minor'], 'service_charge_minor' => (int) $f['service_charge_minor'], 'tax_minor' => (int) $f['tax_minor'], 'reported_on' => substr((string) $f['reported_on'], 0, 10), 'report_reference' => $f['report_reference'],
            'deposited_on' => $f['deposited_on'] === null ? null : substr((string) $f['deposited_on'], 0, 10), 'deposited_minor' => $f['deposited_minor'] === null ? null : (int) $f['deposited_minor'], 'deposit_reference' => $f['deposit_reference'],
            'difference_minor' => $f['deposited_minor'] === null ? null : (int) $f['deposited_minor'] - (int) $f['tax_minor'], 'lock_version' => (int) $f['lock_version'],
        ];
    }
}
