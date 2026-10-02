<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The purchasing policy an owner tunes (all with baselines) and the department budgets (FR-PUR-013). The budget policy is off, a warning or a hard
 * block when a request or an order would take a department past its budget for the month. The tax rate (PPN, 11% to start), the tolerance under which
 * a revision of an approved order needs no new approval, the tolerance for receiving more than was ordered and the tolerances of the invoice match
 * all live here, so none of them is a number buried in code.
 */
final readonly class PurchasingSettingsService
{
    public const POLICIES = ['off', 'warn', 'block'];

    public function __construct(
        private PurchasingStore $store,
        private PurchasingAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyCurrencyReader $currency,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requireView($property, $actorId);
        $settings = $this->settings($property);

        return [
            'currency' => $this->currency->currencyOf($property), 'settings' => $settings, 'policies' => self::POLICIES, 'departments' => InventoryCatalogService::DEPARTMENTS,
            'budgets' => array_map(static fn (array $b): array => ['id' => $b['id'], 'department' => $b['department'], 'period' => $b['period'], 'amount_minor' => (int) $b['amount_minor'], 'lock_version' => (int) $b['lock_version']], $this->store->budgets($property, null)),
            'may' => ['manage' => $this->access->may($property, $actorId, PurchasingAccess::BUDGET_MANAGE)],
        ];
    }

    /** @return array{budget_policy: string, tax_bp: int, po_tolerance_bp: int, over_receipt_bp: int, invoice_price_tolerance_bp: int, invoice_qty_tolerance_bp: int, lock_version: int} */
    public function settings(PropertyId $property): array
    {
        $s = $this->store->settings($property, $this->clock->nowUtc());

        return [
            'budget_policy' => $s['budget_policy'], 'tax_bp' => (int) $s['tax_bp'], 'po_tolerance_bp' => (int) $s['po_tolerance_bp'], 'over_receipt_bp' => (int) $s['over_receipt_bp'],
            'invoice_price_tolerance_bp' => (int) $s['invoice_price_tolerance_bp'], 'invoice_qty_tolerance_bp' => (int) $s['invoice_qty_tolerance_bp'], 'lock_version' => (int) $s['lock_version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function update(PropertyId $property, string $actorId, array $fields, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::BUDGET_MANAGE, 'This person may not change the purchasing policy.');
        $before = $this->settings($property);

        $policy = (string) ($fields['budget_policy'] ?? '');

        if (! in_array($policy, self::POLICIES, true)) {
            throw Refusal::invalid('Choose off, warn or block.', ['budget_policy']);
        }

        $clean = ['budget_policy' => $policy];

        foreach (['tax_bp' => 5000, 'po_tolerance_bp' => 10000, 'over_receipt_bp' => 10000, 'invoice_price_tolerance_bp' => 10000, 'invoice_qty_tolerance_bp' => 10000] as $key => $max) {
            $value = $fields[$key] ?? $before[$key];

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw Refusal::invalid('Give a whole number of basis points (100 = 1%).', [$key]);
            }

            if ((int) $value < 0 || (int) $value > $max) {
                throw Refusal::invalid('This value is from 0 to '.$max.' basis points.', [$key]);
            }

            $clean[$key] = (int) $value;
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $clean, $before, $lock): void {
            if (! $this->store->updateSettings($property, $lock, $clean, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The purchasing policy changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchasing_settings.updated', 'purchasing_settings', $property->toString(), array_diff_key($before, ['lock_version' => 0]), $clean));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function setBudget(PropertyId $property, string $actorId, string $department, string $period, int $amountMinor, ?int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::BUDGET_MANAGE, 'This person may not set budgets.');

        if (! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department.', ['department']);
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw Refusal::invalid('Give the month as year-month.', ['period']);
        }

        if ($amountMinor < 0 || $amountMinor > 9_000_000_000_000) {
            throw Refusal::invalid('Give the budget as a whole amount.', ['amount_minor']);
        }

        $actor = strtolower($actorId);
        $existing = $this->store->budget($property, $department, $period);

        if ($existing !== null && $lock === null) {
            throw Refusal::stateConflict('This department already has a budget for the month. Change it from the list.');
        }

        $this->transactions->run(function () use ($property, $actor, $department, $period, $amountMinor, $lock, $existing): void {
            $id = $existing['id'] ?? $this->ids->next();

            if (! $this->store->setBudget($property, $id, $department, $period, $amountMinor, $existing === null ? null : $lock, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The budget changed after you opened it, or already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'department_budget.set', 'department_budget', $id, $existing === null ? null : ['amount_minor' => (int) $existing['amount_minor']], ['department' => $department, 'period' => $period, 'amount_minor' => $amountMinor]));
        });

        return $this->overview($property, $actorId);
    }

    /**
     * What a department has for a month and what is left, if a document of `$extraMinor` is added. No budget set means nothing is checked.
     *
     * @return array{policy: string, period: string, department: string, budget_minor: int|null, committed_minor: int, extra_minor: int, remaining_minor: int|null, over: bool}
     */
    public function standing(PropertyId $property, string $department, string $period, int $extraMinor, ?string $exceptOrderId = null): array
    {
        $policy = $this->settings($property)['budget_policy'];
        $budget = $this->store->budget($property, $department, $period);
        $committed = $this->store->committed($property, $department, $period, $exceptOrderId);
        $left = $budget === null ? null : (int) $budget['amount_minor'] - $committed - $extraMinor;

        return ['policy' => $policy, 'period' => $period, 'department' => $department, 'budget_minor' => $budget === null ? null : (int) $budget['amount_minor'], 'committed_minor' => $committed, 'extra_minor' => $extraMinor, 'remaining_minor' => $left, 'over' => $left !== null && $left < 0];
    }

    /**
     * Applies the budget policy to a document: a hard block refuses it, a warning is handed back to be shown.
     *
     * @return array<string, mixed>|null the standing when the document goes over a budget under a warning, else null
     */
    public function enforce(PropertyId $property, string $department, string $period, int $extraMinor, ?string $exceptOrderId = null): ?array
    {
        $standing = $this->standing($property, $department, $period, $extraMinor, $exceptOrderId);

        if (! $standing['over'] || $standing['policy'] === 'off') {
            return null;
        }

        if ($standing['policy'] === 'block') {
            throw Refusal::stateConflict('This would take the '.$department.' budget for '.$period.' over its limit, and the policy blocks that.');
        }

        return $standing;
    }
}
