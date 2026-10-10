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
 * The account codes written on the journal lines of the accounting export (FR-FIN-034). Every key has a baseline code and name (a common Indonesian chart for a small hotel); the property changes the ones its
 * accounting software uses, per key, and can go back to the baseline. A key is a fixed one, or `revenue.<outlet code>` / `payment.<method>` for an outlet or a payment method of its own.
 */
final readonly class JournalAccountService
{
    public function __construct(
        private JournalAccountStore $store,
        private FinanceAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, FinanceAccess::EXPORT, 'This person may not export finance data.');

        return $this->shape($property, $actorId);
    }

    /** @return array<string, mixed> */
    private function shape(PropertyId $property, string $actorId): array
    {
        return ['accounts' => JournalAccounts::resolveAll($this->store->overrides($property)), 'may_manage' => $this->access->may($property, $actorId, FinanceAccess::ACCOUNT_MANAGE)];
    }

    /** @return array<string, mixed> */
    public function save(PropertyId $property, string $actorId, string $key, string $code, string $name, ?int $lock): array
    {
        $this->access->require($property, $actorId, FinanceAccess::ACCOUNT_MANAGE, 'This person may not change account codes.');
        $key = strtolower(trim($key));
        $code = trim($code);
        $name = trim($name);

        if (! JournalAccounts::validKey($key)) {
            throw Refusal::invalid('Choose one of the accounts, or give an outlet code or a payment method (revenue.code or payment.method).', ['key']);
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $code) !== 1) {
            throw Refusal::invalid('The code is 1 to 30 letters, digits, dot, dash or underscore.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give the name, at most 80 characters.', ['name']);
        }

        $this->transactions->run(function () use ($property, $actorId, $key, $code, $name, $lock): void {
            $before = $this->store->overrides($property)[$key] ?? null;
            $id = $before['id'] ?? $this->ids->next();

            if (($before !== null && $lock === null) || ! $this->store->save($property, $id, $key, $code, $name, $before === null ? null : $lock, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This account changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'journal_account.saved', 'journal_account', $id, $before === null ? null : ['key' => $key, 'code' => $before['code'], 'name' => $before['name']], ['key' => $key, 'code' => $code, 'name' => $name]));
        });

        return $this->shape($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function reset(PropertyId $property, string $actorId, string $key, int $lock): array
    {
        $this->access->require($property, $actorId, FinanceAccess::ACCOUNT_MANAGE, 'This person may not change account codes.');
        $key = strtolower(trim($key));

        $this->transactions->run(function () use ($property, $actorId, $key, $lock): void {
            $before = $this->store->overrides($property)[$key] ?? throw Refusal::notFound('This account already uses the baseline.');

            if (! $this->store->remove($property, $key, $lock)) {
                throw Refusal::stateConflict('This account changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'journal_account.reset', 'journal_account', $before['id'], ['key' => $key, 'code' => $before['code'], 'name' => $before['name']], null));
        });

        return $this->shape($property, $actorId);
    }
}
