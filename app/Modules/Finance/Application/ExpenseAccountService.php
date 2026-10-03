<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Simple expense accounts grouped by department and category (FR-FIN-010). The code never changes; an account that was used is deactivated, not removed.
 * The departments are the same fixed baseline list the inventory uses; the categories are a fixed baseline list too.
 */
final readonly class ExpenseAccountService
{
    public const DEPARTMENTS = ['front_office', 'housekeeping', 'laundry', 'fnb', 'kitchen', 'maintenance', 'hr', 'finance', 'purchasing', 'general'];

    public const CATEGORIES = ['goods', 'supplies', 'utilities', 'rent', 'maintenance', 'services', 'salaries', 'other'];

    public function __construct(
        private PayableStore $store,
        private FinanceAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requireView($property, $actorId);

        return [
            'accounts' => array_map($this->shape(...), $this->store->accounts($property)), 'departments' => self::DEPARTMENTS, 'categories' => self::CATEGORIES,
            'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::ACCOUNT_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $code, string $name, string $department, string $category): array
    {
        $this->access->require($property, $actorId, FinanceAccess::ACCOUNT_MANAGE, 'This person may not change expense accounts.');
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,11}$/', $code) !== 1) {
            throw Refusal::invalid('The code is 1 to 12 letters, digits, dot, dash or underscore.', ['code']);
        }

        [$name] = $this->clean($name, $department, $category);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $department, $category): void {
            if (! $this->store->addAccount($property, ['id' => $id, 'code' => $code, 'name' => $name, 'department' => $department, 'category' => $category], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('An account with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'expense_account.created', 'expense_account', $id, null, ['code' => $code, 'name' => $name, 'department' => $department, 'category' => $category]));
        });

        return $this->shape($this->store->account($property, $id) ?? throw Refusal::notFound('Account not found.'));
    }

    /** @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, string $name, string $department, string $category, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, FinanceAccess::ACCOUNT_MANAGE, 'This person may not change expense accounts.');
        $before = $this->store->account($property, strtolower($id)) ?? throw Refusal::notFound('Account not found.');
        [$name] = $this->clean($name, $department, $category);

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $department, $category, $active, $lock): void {
            if (! $this->store->updateAccount($property, $before['id'], $lock, ['name' => $name, 'department' => $department, 'category' => $category, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This account changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'expense_account.updated', 'expense_account', $before['id'], ['name' => $before['name'], 'department' => $before['department'], 'category' => $before['category'], 'is_active' => (bool) $before['is_active']], ['name' => $name, 'department' => $department, 'category' => $category, 'is_active' => $active]));
        });

        return $this->shape($this->store->account($property, $before['id']) ?? throw Refusal::notFound('Account not found.'));
    }

    /** @return array{0: string} */
    private function clean(string $name, string $department, string $category): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give the name, at most 80 characters.', ['name']);
        }

        if (! in_array($department, self::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department.', ['department']);
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw Refusal::invalid('Choose a category.', ['category']);
        }

        return [$name];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function shape(array $a): array
    {
        return ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'department' => $a['department'], 'category' => $a['category'], 'is_active' => (bool) $a['is_active'], 'lock_version' => (int) $a['lock_version']];
    }
}
