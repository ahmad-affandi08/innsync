<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The reports finance gives management (FR-FIN-030, FR-FIN-031). They are management reports, not statutory statements and not a general ledger: they
 * read what finance booked and recorded and nothing else.
 *
 * The management P&L is, per department, the net revenue the department earned (base of the charges, apart from service charge and tax) less its direct
 * costs: supplier invoices of the period by invoice date without their tax, classified under an expense account of the department; petty cash vouchers;
 * recurring expenses that were paid, and the value of stock the department consumed (issued, written off or adjusted out, less adjusted in). An invoice for goods for resale is left out of
 * the first cost, because those goods are costed when they are consumed; an invoice not yet classified is shown apart. The revenue of an outlet goes to the
 * department the owner mapped it to (rooms to front office, laundry to laundry and the rest to general by default).
 *
 * The cash flow summary is what came in and went out by cash and bank: guest payments from the booked days (which include what company folios were
 * settled with), receipts against receivables made by hand, supplier payments and petty cash vouchers. The balance at a date is the opening the owner set
 * plus those movements since the opening date.
 */
final readonly class ManagementReportService
{
    /** The longest range of a report, in days. */
    public const MAX_RANGE_DAYS = 366;

    /** The department each built-in revenue outlet goes to until the owner maps it. */
    public const BASELINE_OUTLETS = ['rooms' => 'front_office', 'laundry' => 'laundry', 'other' => 'general'];

    public function __construct(
        private ManagementReportQueries $queries,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function pnl(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->access->requireReportView($property, $actorId);
        [$from, $to, $today] = $this->range($property, $from, $to);
        $mapping = $this->queries->outletMap($property);
        $rows = [];
        $blank = static fn (string $department): array => ['department' => $department, 'revenue_minor' => 0, 'service_charge_minor' => 0, 'expenses_minor' => 0, 'petty_minor' => 0, 'recurring_minor' => 0, 'stock_minor' => 0, 'outlets' => [], 'accounts' => []];
        $unmapped = [];

        foreach ($this->queries->revenueByOutlet($property, $from, $to) as $o) {
            $department = $mapping[$o['outlet_code']] ?? self::BASELINE_OUTLETS[$o['outlet_code']] ?? 'general';

            if (! isset($mapping[$o['outlet_code']]) && ! isset(self::BASELINE_OUTLETS[$o['outlet_code']])) {
                $unmapped[] = $o['outlet_code'];
            }

            $rows[$department] ??= $blank($department);
            $rows[$department]['revenue_minor'] += $o['base_minor'];
            $rows[$department]['service_charge_minor'] += $o['service_charge_minor'];
            $rows[$department]['outlets'][] = ['code' => $o['outlet_code'], 'name' => $o['outlet_name'], 'revenue_minor' => $o['base_minor']];
        }

        $unclassified = 0;
        $goods = 0;

        foreach ($this->queries->payableCosts($property, $from, $to) as $c) {
            if ($c['account_id'] === null) {
                $unclassified += $c['amount_minor'];

                continue;
            }

            if ($c['category'] === 'goods') {
                $goods += $c['amount_minor'];

                continue;
            }

            $d = (string) $c['department'];
            $rows[$d] ??= $blank($d);
            $rows[$d]['expenses_minor'] += $c['amount_minor'];
            $rows[$d]['accounts'][(string) $c['code']] = ['code' => $c['code'], 'name' => $c['name'], 'category' => $c['category'], 'expenses_minor' => $c['amount_minor'], 'petty_minor' => 0, 'recurring_minor' => 0];
        }

        foreach ($this->queries->pettyCosts($property, $from, $to) as $c) {
            $rows[$c['department']] ??= $blank($c['department']);
            $rows[$c['department']]['petty_minor'] += $c['amount_minor'];
            $a = $rows[$c['department']]['accounts'][$c['code']] ?? ['code' => $c['code'], 'name' => $c['name'], 'category' => $c['category'], 'expenses_minor' => 0, 'petty_minor' => 0, 'recurring_minor' => 0];
            $a['petty_minor'] += $c['amount_minor'];
            $rows[$c['department']]['accounts'][$c['code']] = $a;
        }

        foreach ($this->queries->recurringCosts($property, $from, $to) as $c) {
            $rows[$c['department']] ??= $blank($c['department']);
            $rows[$c['department']]['recurring_minor'] += $c['amount_minor'];
            $a = $rows[$c['department']]['accounts'][$c['code']] ?? ['code' => $c['code'], 'name' => $c['name'], 'category' => $c['category'], 'expenses_minor' => 0, 'petty_minor' => 0, 'recurring_minor' => 0];
            $a['recurring_minor'] += $c['amount_minor'];
            $rows[$c['department']]['accounts'][$c['code']] = $a;
        }

        foreach ($this->queries->stockConsumption($property, $from, $to) as $department => $value) {
            if ($value !== 0) {
                $rows[$department] ??= $blank($department);
                $rows[$department]['stock_minor'] += $value;
            }
        }

        $order = array_flip(ExpenseAccountService::DEPARTMENTS);
        uksort($rows, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $departments = [];
        $totals = ['revenue_minor' => 0, 'service_charge_minor' => 0, 'expenses_minor' => 0, 'petty_minor' => 0, 'recurring_minor' => 0, 'stock_minor' => 0, 'cost_total_minor' => 0, 'result_minor' => 0];

        foreach ($rows as $r) {
            $cost = $r['expenses_minor'] + $r['petty_minor'] + $r['recurring_minor'] + $r['stock_minor'];
            $result = $r['revenue_minor'] - $cost;
            ksort($r['accounts']);
            $departments[] = [...$r, 'accounts' => array_values($r['accounts']), 'cost_total_minor' => $cost, 'result_minor' => $result, 'margin_bp' => $r['revenue_minor'] > 0 ? intdiv($result * 10_000, $r['revenue_minor']) : null];

            foreach (['revenue_minor', 'service_charge_minor', 'expenses_minor', 'petty_minor', 'recurring_minor', 'stock_minor'] as $k) {
                $totals[$k] += $r[$k];
            }

            $totals['cost_total_minor'] += $cost;
            $totals['result_minor'] += $result;
        }

        $outlets = [];

        foreach ($this->queries->revenueByOutlet($property, '2000-01-01', '2100-12-31') as $o) {
            $outlets[$o['outlet_code']] = ['code' => $o['outlet_code'], 'name' => $o['outlet_name'], 'department' => $mapping[$o['outlet_code']] ?? self::BASELINE_OUTLETS[$o['outlet_code']] ?? 'general', 'mapped' => isset($mapping[$o['outlet_code']]) || isset(self::BASELINE_OUTLETS[$o['outlet_code']])];
        }

        return [
            'from' => $from, 'to' => $to, 'today' => $today, 'currency' => $this->currencies->currencyOf($property), 'departments' => $departments, 'totals' => [...$totals, 'margin_bp' => $totals['revenue_minor'] > 0 ? intdiv($totals['result_minor'] * 10_000, $totals['revenue_minor']) : null],
            'notes' => ['unclassified_payables_minor' => $unclassified, 'goods_payables_minor' => $goods, 'unmapped_outlets' => array_values(array_unique($unmapped)), 'unverified_days' => $this->queries->unverifiedDays($property, $from, $to)],
            'mapping' => array_values($outlets), 'department_list' => ExpenseAccountService::DEPARTMENTS, 'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::ACCOUNT_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function cashFlow(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->access->requireReportView($property, $actorId);
        [$from, $to, $today] = $this->range($property, $from, $to);
        $movement = $this->movements($property, $from, $to);
        $settings = $this->queries->cashSettings($property);
        $balances = null;

        if ($settings !== null) {
            $since = $this->movements($property, $settings['opening_date'], $to)['net'];
            $started = $settings['opening_date'] <= $to;
            $balances = [
                'opening_date' => $settings['opening_date'], 'lock_version' => $settings['lock_version'], 'reached' => $started,
                'cash' => ['opening_minor' => $settings['cash_opening_minor'], 'closing_minor' => $started ? $settings['cash_opening_minor'] + $since['cash'] : null],
                'bank' => ['opening_minor' => $settings['bank_opening_minor'], 'closing_minor' => $started ? $settings['bank_opening_minor'] + $since['bank'] : null],
            ];
        }

        return [
            'from' => $from, 'to' => $to, 'today' => $today, 'currency' => $this->currencies->currencyOf($property), ...$movement, 'balances' => $balances, 'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::ACCOUNT_MANAGE)],
        ];
    }

    /** @return array{outlet_code: string, department: string} */
    public function mapOutlet(PropertyId $property, string $actorId, string $outletCode, string $department): array
    {
        $this->access->require($property, $actorId, FinanceAccess::ACCOUNT_MANAGE, 'This person may not change how outlets map to departments.');

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/D', $outletCode) !== 1) {
            throw Refusal::invalid('Choose an outlet from the list.', ['outlet_code']);
        }

        if (! in_array($department, ExpenseAccountService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department from the list.', ['department']);
        }

        $actor = strtolower($actorId);
        $before = $this->queries->outletMap($property)[$outletCode] ?? self::BASELINE_OUTLETS[$outletCode] ?? null;

        $this->transactions->run(function () use ($property, $actor, $outletCode, $department, $before): void {
            $this->queries->saveOutletMapping($property, $outletCode, $department, $actor, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'pnl.outlet_mapped', 'pnl_outlet', $outletCode, ['department' => $before], ['department' => $department]));
        });

        return ['outlet_code' => $outletCode, 'department' => $department];
    }

    /** @return array<string, mixed> the balances of the cash flow report as they stand */
    public function setOpening(PropertyId $property, string $actorId, int $cashMinor, int $bankMinor, string $openingDate, ?int $expectedLockVersion, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::ACCOUNT_MANAGE, 'This person may not set the opening cash and bank balances.');
        $reason = trim($reason);

        if ($cashMinor < -9_000_000_000_000 || $cashMinor > 9_000_000_000_000 || $bankMinor < -9_000_000_000_000 || $bankMinor > 9_000_000_000_000) {
            throw Refusal::invalid('Give the balances as amounts of at most nine trillion.', ['cash_opening_minor']);
        }

        $openingDate = $this->date($openingDate, 'opening_date');
        $today = $this->businessDate->current($property)->toString();

        if ($openingDate > $today) {
            throw Refusal::invalid('The opening date cannot be in the future.', ['opening_date']);
        }

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('Say why, in at most 300 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $cashMinor, $bankMinor, $openingDate, $expectedLockVersion, $reason): void {
            $before = $this->queries->cashSettings($property);

            if (($before === null) !== ($expectedLockVersion === null)) {
                throw Refusal::stateConflict('The opening balances changed after you opened them. Reload them.');
            }

            if (! $this->queries->saveCashSettings($property, $cashMinor, $bankMinor, $openingDate, $expectedLockVersion, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The opening balances changed after you opened them. Reload them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'cash_opening.set', 'cash_opening', $property->toString(), $before, ['cash_opening_minor' => $cashMinor, 'bank_opening_minor' => $bankMinor, 'opening_date' => $openingDate], $reason));
        });

        return $this->cashFlow($property, $actorId, null, null)['balances'] ?? [];
    }

    /** @return array{receipts: list<array<string, mixed>>, payments: list<array<string, mixed>>, totals: array<string, array<string, int>>, net: array<string, int>} */
    private function movements(PropertyId $property, string $from, string $to): array
    {
        $group = static fn (string $method): string => $method === 'cash' ? 'cash' : 'bank';
        $receipts = [];
        $payments = [];
        $in = ['cash' => 0, 'bank' => 0];
        $out = ['cash' => 0, 'bank' => 0];

        foreach ($this->queries->guestPayments($property, $from, $to) as $p) {
            $net = $p['received_minor'] - $p['paid_back_minor'];
            $receipts[] = ['source' => 'guest', 'method' => $p['method'], 'group' => $group($p['method']), 'amount_minor' => $net, 'received_minor' => $p['received_minor'], 'paid_back_minor' => $p['paid_back_minor']];
            $in[$group($p['method'])] += $net;
        }

        foreach ($this->queries->manualReceipts($property, $from, $to) as $p) {
            $receipts[] = ['source' => 'receivable', 'method' => $p['method'], 'group' => 'bank', 'amount_minor' => $p['amount_minor'], 'received_minor' => $p['amount_minor'], 'paid_back_minor' => 0];
            $in['bank'] += $p['amount_minor'];
        }

        foreach ($this->queries->supplierPayments($property, $from, $to) as $p) {
            $payments[] = ['source' => 'supplier', 'method' => $p['method'], 'group' => $group($p['method']), 'amount_minor' => $p['amount_minor']];
            $out[$group($p['method'])] += $p['amount_minor'];
        }

        foreach ($this->queries->recurringPayments($property, $from, $to) as $p) {
            $payments[] = ['source' => 'recurring', 'method' => $p['method'], 'group' => $group($p['method']), 'amount_minor' => $p['amount_minor']];
            $out[$group($p['method'])] += $p['amount_minor'];
        }

        $petty = $this->queries->pettySpent($property, $from, $to);

        if ($petty > 0) {
            $payments[] = ['source' => 'petty', 'method' => 'cash', 'group' => 'cash', 'amount_minor' => $petty];
            $out['cash'] += $petty;
        }

        return [
            'receipts' => $receipts, 'payments' => $payments,
            'totals' => ['in' => [...$in, 'total' => $in['cash'] + $in['bank']], 'out' => [...$out, 'total' => $out['cash'] + $out['bank']]],
            'net' => ['cash' => $in['cash'] - $out['cash'], 'bank' => $in['bank'] - $out['bank'], 'total' => $in['cash'] + $in['bank'] - $out['cash'] - $out['bank']],
        ];
    }

    /** @return array{0: string, 1: string, 2: string} the range of a report as it is shown (from, to, today); the range of every finance report follows the same rules */
    public function period(PropertyId $property, ?string $from, ?string $to): array
    {
        return $this->range($property, $from, $to);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function range(PropertyId $property, ?string $from, ?string $to): array
    {
        $today = $this->businessDate->current($property)->toString();
        $to = $to === null || $to === '' ? $today : $this->date($to, 'to');
        $from = $from === null || $from === '' ? substr($to, 0, 8).'01' : $this->date($from, 'from');

        if ($from > $to) {
            throw Refusal::invalid('The start of the range is after its end.', ['from']);
        }

        if ((int) (new DateTimeImmutable($from, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($to, new DateTimeZone('UTC')))->days >= self::MAX_RANGE_DAYS) {
            throw Refusal::invalid('Choose a range of at most a year.', ['to']);
        }

        return [$from, $to, $today];
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }
}
