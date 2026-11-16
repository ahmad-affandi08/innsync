<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The commissions of employees (docs/OPERATIONS/EMPLOYEE-COMMISSIONS.md). The manager adds the kinds of commission the hotel pays and records what a person earned on a day; the person reads everything they earned
 * since they began from their own account. An entry is never changed or deleted: a mistake is reversed by an entry of the opposite amount, once, with the reason kept. The spa therapist's commission on a
 * treatment is reported by the spa itself and is not copied here.
 */
final readonly class EmployeeCommissionService
{
    /** The most an entry may be for, in the smallest unit: a guard against a slipped finger, not a policy. */
    private const LIMIT_MINOR = 100_000_000_00;

    private const LIST_LIMIT = 300;

    public function __construct(
        private EmployeeCommissionStore $store,
        private EmployeeStore $employees,
        private HrAccess $access,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** The manager's page. @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $employeeId = null): array
    {
        $this->access->require($property, $actorId, HrAccess::COMMISSION, 'This person may not manage commissions.');
        $employeeId = $employeeId === null || $employeeId === '' ? null : strtolower($employeeId);

        return [
            'currency' => $this->currency->currencyOf($property),
            'today' => $this->businessDate->current($property)->toString(),
            'types' => array_map(self::type(...), $this->store->types($property)),
            'employees' => array_values(array_map(static fn (array $e): array => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name']], $this->employees->employees($property, 'active'))),
            'employee_id' => $employeeId,
            'entries' => array_map(self::entry(...), $this->store->entries($property, $employeeId, self::LIST_LIMIT)),
            'totals' => $employeeId === null ? [] : $this->store->totalsOf($property, $employeeId),
        ];
    }

    /** The employee's own page: everything they earned since they began. @return array<string, mixed> */
    public function mine(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $id = $this->employees->employeeOfUser($property, strtolower($actorId));
        $currency = $this->currency->currencyOf($property);

        if ($id === null) {
            return ['linked' => false, 'currency' => $currency, 'total_minor' => 0, 'by_type' => [], 'entries' => []];
        }

        $totals = $this->store->totalsOf($property, $id);

        return [
            'linked' => true, 'currency' => $currency, 'total_minor' => array_sum(array_column($totals, 'total_minor')),
            'by_type' => $totals, 'entries' => array_map(self::entry(...), $this->store->entries($property, $id, self::LIST_LIMIT)),
        ];
    }

    /** @return array<string, mixed> */
    public function addType(PropertyId $property, string $actorId, string $code, string $name): array
    {
        $this->access->require($property, $actorId, HrAccess::COMMISSION, 'This person may not manage commissions.');
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9_-]{2,20}$/D', $code) !== 1 || $name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a code of 2 to 20 letters or digits and a name of at most 80 characters.', ['code', 'name']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $code, $name): void {
            if (! $this->store->addType($property, ['id' => $id, 'code' => $code, 'name' => $name, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::invalid('This code is already used.', ['code']);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'commission_type.created', 'commission_type', $id, null, ['code' => $code, 'name' => $name]));
        });

        return self::type($this->store->type($property, $id) ?? throw Refusal::notFound('Commission type not found.'));
    }

    /** @return array<string, mixed> */
    public function updateType(PropertyId $property, string $actorId, string $id, string $name, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::COMMISSION, 'This person may not manage commissions.');
        $name = trim($name);
        $type = $this->store->type($property, strtolower($id)) ?? throw Refusal::notFound('Commission type not found.');

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a name of at most 80 characters.', ['name']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $type, $name, $active, $lock): void {
            if (! $this->store->updateType($property, $type['id'], $lock, $name, $active, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This kind of commission changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'commission_type.updated', 'commission_type', $type['id'], ['name' => $type['name'], 'is_active' => (bool) $type['is_active']], ['name' => $name, 'is_active' => $active]));
        });

        return self::type($this->store->type($property, $type['id']) ?? throw Refusal::notFound('Commission type not found.'));
    }

    /** What a person earned on a day. @return array<string, mixed> */
    public function record(PropertyId $property, string $actorId, string $employeeId, string $typeId, string $earnedOn, int $amountMinor, ?string $note): array
    {
        $this->access->require($property, $actorId, HrAccess::COMMISSION, 'This person may not manage commissions.');
        $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::invalid('Choose an employee.', ['employee_id']);
        $type = $this->store->type($property, strtolower($typeId));
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($type === null || ! (bool) $type['is_active']) {
            throw Refusal::invalid('Choose a kind of commission from the list.', ['type_id']);
        }

        if ($amountMinor < 1 || $amountMinor > self::LIMIT_MINOR) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $earnedOn) !== 1 || $earnedOn > $this->businessDate->current($property)->toString()) {
            throw Refusal::invalid('Give the day it was earned, today or before.', ['earned_on']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $employee, $type, $earnedOn, $amountMinor, $note): void {
            $this->store->addEntry($property, ['id' => $id, 'employee_id' => $employee['id'], 'type_id' => $type['id'], 'earned_on' => $earnedOn, 'amount_minor' => $amountMinor, 'note' => $note, 'reverses_id' => null, 'entered_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'commission.recorded', 'commission_entry', $id, null, ['employee' => $employee['number'], 'type' => $type['code'], 'earned_on' => $earnedOn, 'amount_minor' => $amountMinor], $note));
        });

        return self::entry($this->store->entryView($property, $id) ?? throw Refusal::notFound('Commission entry not found.'));
    }

    /** Takes back an entry made by mistake with an entry of the opposite amount; an entry is reversed once. @return array<string, mixed> */
    public function reverse(PropertyId $property, string $actorId, string $entryId, string $reason): array
    {
        $this->access->require($property, $actorId, HrAccess::COMMISSION, 'This person may not manage commissions.');
        $reason = trim($reason);
        $entry = $this->store->entry($property, strtolower($entryId)) ?? throw Refusal::notFound('Commission entry not found.');

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason, at most 200 characters.', ['reason']);
        }

        if ($entry['reverses_id'] !== null) {
            throw Refusal::stateConflict('This entry is itself a reversal.');
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $entry, $reason): void {
            $row = ['id' => $id, 'employee_id' => $entry['employee_id'], 'type_id' => $entry['type_id'], 'earned_on' => $this->businessDate->current($property)->toString(), 'amount_minor' => -(int) $entry['amount_minor'], 'note' => mb_substr('Reversal: '.$reason, 0, 200), 'reverses_id' => $entry['id'], 'entered_by' => $actor];

            if (! $this->store->addEntry($property, $row, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This entry was reversed already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'commission.reversed', 'commission_entry', $entry['id'], ['amount_minor' => (int) $entry['amount_minor']], ['reversal_id' => $id], $reason));
        });

        return self::entry($this->store->entryView($property, $id) ?? throw Refusal::notFound('Commission entry not found.'));
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private static function type(array $t): array
    {
        return ['id' => $t['id'], 'code' => $t['code'], 'name' => $t['name'], 'is_active' => (bool) $t['is_active'], 'lock_version' => (int) $t['lock_version']];
    }

    /** @param array<string, mixed> $e @return array<string, mixed> */
    private static function entry(array $e): array
    {
        return [
            'id' => $e['id'], 'employee_id' => $e['employee_id'], 'employee_number' => $e['number'] ?? null, 'employee_name' => $e['full_name'] ?? null, 'type_code' => $e['type_code'] ?? null, 'type_name' => $e['type_name'] ?? null,
            'earned_on' => substr((string) $e['earned_on'], 0, 10), 'amount_minor' => (int) $e['amount_minor'], 'note' => $e['note'], 'reverses_id' => $e['reverses_id'],
        ];
    }
}
