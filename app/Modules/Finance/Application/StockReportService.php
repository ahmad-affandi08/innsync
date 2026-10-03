<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The two reports finance takes from the inventory ledger.
 *
 * The stock value report (FR-FIN-033) is the value of the stock at the end of a date, by the department of the item and by location, and what moved it
 * from the day before the range to the end: received (opening stock and goods received), returned to suppliers, issued, written off and adjusted. A
 * move between locations changes no department's value, so it is not a column. The figures are the ledger's own and agree with the inventory valuation.
 *
 * The food cost report (FR-FIN-032) sets the value of the ingredients the food and beverage departments (outlets and kitchen) consumed in the range against
 * the sales of the outlets the owner mapped to food and beverage in the P&L. The cost is what was issued, written off or adjusted out, less adjusted in, the
 * same figure the P&L takes. The target share is the owner's; until it is set the baseline of 35% is used. Stock is not kept per outlet, so the cost is
 * for all the food and beverage outlets together.
 */
final readonly class StockReportService
{
    /** The departments whose consumption is the cost of food and beverage. */
    public const FOOD_DEPARTMENTS = ['fnb', 'kitchen'];

    /** The share of sales that ingredients may cost until the owner sets one, in basis points (35%). */
    public const BASELINE_TARGET_BP = 3500;

    public const MIN_TARGET_BP = 500;

    public const MAX_TARGET_BP = 9000;

    public function __construct(
        private StockReportQueries $stock,
        private ManagementReportQueries $revenue,
        private ManagementReportService $reports,
        private FinanceAccess $access,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function value(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->access->requireReportView($property, $actorId);
        [$from, $to, $today] = $this->reports->period($property, $from, $to);
        $before = (new DateTimeImmutable($from, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
        $blank = static fn (string $d): array => ['department' => $d, 'opening_minor' => 0, 'received_minor' => 0, 'returned_minor' => 0, 'issued_minor' => 0, 'written_off_minor' => 0, 'adjusted_minor' => 0, 'closing_minor' => 0];
        $rows = [];

        foreach ($this->stock->valueAt($property, $before) as $v) {
            $rows[$v['department']] ??= $blank($v['department']);
            $rows[$v['department']]['opening_minor'] += $v['value_minor'];
        }

        foreach ($this->stock->movedBetween($property, $from, $to) as $m) {
            $column = match ($m['kind']) {
                'opening', 'receipt' => 'received_minor',
                'return_out' => 'returned_minor',
                'issue' => 'issued_minor',
                'write_off' => 'written_off_minor',
                'adjustment_in', 'adjustment_out' => 'adjusted_minor',
                default => null,
            };

            if ($column !== null) {
                $rows[$m['department']] ??= $blank($m['department']);
                $rows[$m['department']][$column] += $m['value_minor'];
            }
        }

        $locations = [];

        foreach ($this->stock->valueAt($property, $to) as $v) {
            $rows[$v['department']] ??= $blank($v['department']);
            $rows[$v['department']]['closing_minor'] += $v['value_minor'];
            $locations[$v['location_id']] ??= ['id' => $v['location_id'], 'code' => $v['location_code'], 'name' => $v['location_name'], 'value_minor' => 0];
            $locations[$v['location_id']]['value_minor'] += $v['value_minor'];
        }

        $order = array_flip(ExpenseAccountService::DEPARTMENTS);
        uksort($rows, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $totals = $blank('');
        unset($totals['department']);

        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        $locations = array_values($locations);
        usort($locations, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return [
            'from' => $from, 'to' => $to, 'today' => $today, 'opening_as_of' => $before, 'currency' => $this->currencies->currencyOf($property),
            'departments' => array_values($rows), 'totals' => $totals, 'locations' => $locations,
        ];
    }

    /** @return array<string, mixed> */
    public function foodCost(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->access->requireReportView($property, $actorId);
        [$from, $to, $today] = $this->reports->period($property, $from, $to);
        $mapping = $this->revenue->outletMap($property);
        $outlets = [];
        $sales = 0;

        foreach ($this->revenue->revenueByOutlet($property, $from, $to) as $o) {
            $department = $mapping[$o['outlet_code']] ?? ManagementReportService::BASELINE_OUTLETS[$o['outlet_code']] ?? 'general';

            if ($department === 'fnb') {
                $outlets[] = ['code' => $o['outlet_code'], 'name' => $o['outlet_name'], 'sales_minor' => $o['base_minor']];
                $sales += $o['base_minor'];
            }
        }

        $blank = static fn (string $d): array => ['department' => $d, 'issued_minor' => 0, 'written_off_minor' => 0, 'adjusted_minor' => 0, 'cost_minor' => 0, 'purchased_minor' => 0];
        $rows = [];

        foreach (self::FOOD_DEPARTMENTS as $d) {
            $rows[$d] = $blank($d);
        }

        foreach ($this->stock->movedBetween($property, $from, $to) as $m) {
            if (! isset($rows[$m['department']])) {
                continue;
            }

            $d = $m['department'];

            match ($m['kind']) {
                'issue' => $rows[$d]['issued_minor'] -= $m['value_minor'],
                'write_off' => $rows[$d]['written_off_minor'] -= $m['value_minor'],
                'adjustment_in', 'adjustment_out' => $rows[$d]['adjusted_minor'] -= $m['value_minor'],
                'receipt' => $rows[$d]['purchased_minor'] += $m['value_minor'],
                default => null,
            };
        }

        $totals = $blank('');
        unset($totals['department']);

        foreach ($rows as $d => $r) {
            $rows[$d]['cost_minor'] = $r['issued_minor'] + $r['written_off_minor'] + $r['adjusted_minor'];

            foreach ($totals as $k => $_) {
                $totals[$k] += $rows[$d][$k];
            }
        }

        $settings = $this->stock->foodCostSettings($property);
        $target = $settings['target_bp'] ?? self::BASELINE_TARGET_BP;
        $cost = $totals['cost_minor'];
        $share = $sales > 0 ? intdiv($cost * 10_000, $sales) : null;
        $allowed = intdiv($sales * $target, 10_000);

        return [
            'from' => $from, 'to' => $to, 'today' => $today, 'currency' => $this->currencies->currencyOf($property),
            'outlets' => $outlets, 'sales_minor' => $sales, 'departments' => array_values($rows), 'totals' => $totals,
            'food_cost_bp' => $share, 'waste_bp' => $cost > 0 ? intdiv($totals['written_off_minor'] * 10_000, $cost) : null,
            'target' => ['bp' => $target, 'is_default' => $settings === null, 'lock_version' => $settings['lock_version'] ?? null, 'allowed_minor' => $allowed],
            'status' => $sales === 0 ? 'no_sales' : ($cost > $allowed ? 'over' : 'within'), 'over_minor' => max(0, $cost - $allowed),
            'notes' => ['outlets_mapped' => count($outlets) > 0, 'unverified_days' => $this->revenue->unverifiedDays($property, $from, $to)],
            'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::ACCOUNT_MANAGE)],
        ];
    }

    /** @return array{bp: int, lock_version: int} the target as it stands */
    public function setFoodCostTarget(PropertyId $property, string $actorId, int $targetBp, ?int $expectedLockVersion, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::ACCOUNT_MANAGE, 'This person may not set the food cost target.');
        $reason = trim($reason);

        if ($targetBp < self::MIN_TARGET_BP || $targetBp > self::MAX_TARGET_BP) {
            throw Refusal::invalid('Give a target between 5% and 90% of sales.', ['target_bp']);
        }

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('Say why, in at most 300 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $targetBp, $expectedLockVersion, $reason): void {
            $before = $this->stock->foodCostSettings($property);

            if (($before === null) !== ($expectedLockVersion === null)) {
                throw Refusal::stateConflict('The target changed after you opened it. Reload it.');
            }

            if (! $this->stock->saveFoodCostTarget($property, $targetBp, $expectedLockVersion, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The target changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'food_cost.target_set', 'food_cost_target', $property->toString(), ['target_bp' => $before['target_bp'] ?? self::BASELINE_TARGET_BP], ['target_bp' => $targetBp], $reason));
        });

        $settings = $this->stock->foodCostSettings($property);

        return ['bp' => $targetBp, 'lock_version' => $settings['lock_version'] ?? 0];
    }
}
