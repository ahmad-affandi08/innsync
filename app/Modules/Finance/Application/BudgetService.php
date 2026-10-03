<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

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
 * The budget of each department by month (FR-FIN-017): the net revenue it should earn and the direct cost it may spend, in the same terms as the management P&L
 * (revenue without service charge and tax; costs from invoices without tax, petty cash, recurring expenses and stock consumed). The report sets a range of up to
 * twelve months of budget against what the P&L shows for the same months: what was spent or earned, the difference, and how much of the budget it is. Changing a
 * month is audited with the figures before and after and a reason; nothing the budget says changes a booked figure.
 */
final readonly class BudgetService
{
    public const MAX_MONTHS = 12;

    private const MAX_MINOR = 9_000_000_000_000;

    public function __construct(
        private BudgetStore $store,
        private ManagementReportService $reports,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> the budgets of a year, every department */
    public function overview(PropertyId $property, string $actorId, ?int $year): array
    {
        $this->access->requireReportView($property, $actorId);
        $year ??= (int) substr($this->businessDate->current($property)->toString(), 0, 4);
        $this->assertYear($year);
        $rows = [];

        foreach ($this->store->budgets($property, sprintf('%04d-01-01', $year), sprintf('%04d-12-01', $year), null) as $b) {
            $rows[] = ['month' => (int) substr($b['month'], 5, 2), 'department' => $b['department'], 'revenue_minor' => $b['revenue_minor'], 'cost_minor' => $b['cost_minor']];
        }

        return ['year' => $year, 'departments' => ExpenseAccountService::DEPARTMENTS, 'rows' => $rows, 'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::BUDGET_MANAGE)]];
    }

    /**
     * @param  list<array{month: int, revenue_minor: int, cost_minor: int}>  $months
     * @return array<string, mixed> the budgets of the year
     */
    public function save(PropertyId $property, string $actorId, int $year, string $department, array $months, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::BUDGET_MANAGE, 'This person may not set budgets.');
        $this->assertYear($year);
        $reason = trim($reason);

        if (! in_array($department, ExpenseAccountService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department from the list.', ['department']);
        }

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('Say why the budget is set or changed, in at most 300 characters.', ['reason']);
        }

        if ($months === [] || count($months) > 12) {
            throw Refusal::invalid('Give the budget of one to twelve months.', ['months']);
        }

        $seen = [];

        foreach ($months as $m) {
            if ($m['month'] < 1 || $m['month'] > 12 || isset($seen[$m['month']])) {
                throw Refusal::invalid('Each month is given once, 1 to 12.', ['months']);
            }

            $seen[$m['month']] = true;

            if ($m['revenue_minor'] < 0 || $m['revenue_minor'] > self::MAX_MINOR || $m['cost_minor'] < 0 || $m['cost_minor'] > self::MAX_MINOR) {
                throw Refusal::invalid('A budget is zero or more.', ['months']);
            }
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $year, $department, $months, $reason): void {
            $before = [];

            foreach ($this->store->budgets($property, sprintf('%04d-01-01', $year), sprintf('%04d-12-01', $year), $department) as $b) {
                $before[(int) substr($b['month'], 5, 2)] = ['revenue_minor' => $b['revenue_minor'], 'cost_minor' => $b['cost_minor']];
            }

            $after = [];

            foreach ($months as $m) {
                $this->store->save($property, sprintf('%04d-%02d-01', $year, $m['month']), $department, $m['revenue_minor'], $m['cost_minor'], $actor, $this->clock->nowUtc());
                $after[$m['month']] = ['revenue_minor' => $m['revenue_minor'], 'cost_minor' => $m['cost_minor']];
            }

            ksort($after);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'budget.set', 'budget', $year.':'.$department, $before === [] ? null : ['months' => $before], ['months' => $after], $reason));
        });

        return $this->overview($property, $actorId, $year);
    }

    /** @return array<string, mixed> */
    public function report(PropertyId $property, string $actorId, ?string $fromMonth, ?string $toMonth): array
    {
        $this->access->requireReportView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $toMonth = $toMonth === null || $toMonth === '' ? substr($today, 0, 7) : $this->month($toMonth, 'to');
        $fromMonth = $fromMonth === null || $fromMonth === '' ? substr($toMonth, 0, 4).'-01' : $this->month($fromMonth, 'from');

        if ($fromMonth > $toMonth) {
            throw Refusal::invalid('The first month is after the last.', ['from']);
        }

        $months = [];

        for ($d = new DateTimeImmutable($fromMonth.'-01', new DateTimeZone('UTC')); $d->format('Y-m') <= $toMonth; $d = $d->modify('+1 month')) {
            $months[] = $d->format('Y-m');

            if (count($months) > self::MAX_MONTHS) {
                throw Refusal::invalid('Choose at most twelve months.', ['to']);
            }
        }

        $first = $fromMonth.'-01';
        $last = (new DateTimeImmutable($toMonth.'-01', new DateTimeZone('UTC')))->format('Y-m-t');
        $actual = $this->reports->pnl($property, $actorId, $first, $last);
        $rows = [];
        $blank = static fn (string $department): array => ['department' => $department, 'budget_revenue_minor' => 0, 'actual_revenue_minor' => 0, 'budget_cost_minor' => 0, 'actual_cost_minor' => 0];

        foreach ($this->store->budgets($property, $first, $toMonth.'-01', null) as $b) {
            $rows[$b['department']] ??= $blank($b['department']);
            $rows[$b['department']]['budget_revenue_minor'] += $b['revenue_minor'];
            $rows[$b['department']]['budget_cost_minor'] += $b['cost_minor'];
        }

        foreach ($actual['departments'] as $d) {
            $rows[$d['department']] ??= $blank($d['department']);
            $rows[$d['department']]['actual_revenue_minor'] = $d['revenue_minor'];
            $rows[$d['department']]['actual_cost_minor'] = $d['cost_total_minor'];
        }

        $order = array_flip(ExpenseAccountService::DEPARTMENTS);
        uksort($rows, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $departments = array_map(fn (array $r): array => $this->compare($r), array_values($rows));
        $totals = $blank('total');

        foreach ($departments as $r) {
            foreach (['budget_revenue_minor', 'actual_revenue_minor', 'budget_cost_minor', 'actual_cost_minor'] as $k) {
                $totals[$k] += $r[$k];
            }
        }

        $monthly = [];

        foreach ($months as $m) {
            $a = $this->reports->pnl($property, $actorId, $m.'-01', (new DateTimeImmutable($m.'-01', new DateTimeZone('UTC')))->format('Y-m-t'))['totals'];
            $budget = ['revenue_minor' => 0, 'cost_minor' => 0];

            foreach ($this->store->budgets($property, $m.'-01', $m.'-01', null) as $b) {
                $budget['revenue_minor'] += $b['revenue_minor'];
                $budget['cost_minor'] += $b['cost_minor'];
            }

            $monthly[] = ['month' => $m, 'budget_revenue_minor' => $budget['revenue_minor'], 'actual_revenue_minor' => $a['revenue_minor'], 'budget_cost_minor' => $budget['cost_minor'], 'actual_cost_minor' => $a['cost_total_minor']];
        }

        return [
            'from' => $fromMonth, 'to' => $toMonth, 'currency' => $actual['currency'], 'departments' => $departments, 'totals' => $this->compare($totals), 'monthly' => $monthly,
            'notes' => ['unverified_days' => $actual['notes']['unverified_days'], 'unclassified_payables_minor' => $actual['notes']['unclassified_payables_minor']], 'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::BUDGET_MANAGE)],
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function compare(array $r): array
    {
        $budgetResult = $r['budget_revenue_minor'] - $r['budget_cost_minor'];
        $actualResult = $r['actual_revenue_minor'] - $r['actual_cost_minor'];

        return [
            ...$r, 'revenue_variance_minor' => $r['actual_revenue_minor'] - $r['budget_revenue_minor'], 'cost_variance_minor' => $r['actual_cost_minor'] - $r['budget_cost_minor'],
            'budget_result_minor' => $budgetResult, 'actual_result_minor' => $actualResult, 'result_variance_minor' => $actualResult - $budgetResult,
            'revenue_used_bp' => $r['budget_revenue_minor'] > 0 ? intdiv($r['actual_revenue_minor'] * 10_000, $r['budget_revenue_minor']) : null,
            'cost_used_bp' => $r['budget_cost_minor'] > 0 ? intdiv($r['actual_cost_minor'] * 10_000, $r['budget_cost_minor']) : null,
        ];
    }

    private function assertYear(int $year): void
    {
        if ($year < 2000 || $year > 2100) {
            throw Refusal::invalid('Give a year between 2000 and 2100.', ['year']);
        }
    }

    private function month(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            throw Refusal::invalid('Give the month as year-month.', [$field]);
        }

        return $value;
    }
}
