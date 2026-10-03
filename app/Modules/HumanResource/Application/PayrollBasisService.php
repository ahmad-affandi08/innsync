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
 * What each person earns (FR-HR-030). A line gives the amount of one kind of earning from a date on, and is never changed: a raise is a new line with a later date and the one in force on a day is the latest that
 * starts on or before it. A line cannot start before the first day of the month of the business date, so a period that was worked out is not rewritten (a correction of the past is an adjustment in a later period).
 * The tax status and schemes of each person are kept beside it.
 */
final readonly class PayrollBasisService
{
    public function __construct(
        private PayrollStore $store,
        private EmployeeStore $employees,
        private PayrollSettingsService $settings,
        private HrAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currency,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $employeeId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not see what people earn.');
        $today = $this->businessDate->current($property)->toString();
        $components = array_map(PayComponentService::shape(...), $this->store->components($property, false));
        $kind = array_column($components, 'kind', 'id');
        $items = $this->store->items($property, null);
        $profiles = array_column($this->store->profiles($property), null, 'employee_id');
        $people = [];

        foreach ($this->employees->employees($property, 'active') as $e) {
            $current = self::inForce(array_values(array_filter($items, static fn (array $i): bool => $i['employee_id'] === $e['id'])), $today);
            $monthly = 0;
            $perDay = 0;

            foreach ($current as $componentId => $line) {
                if (($kind[$componentId] ?? null) === null) {
                    continue;
                }

                $kind[$componentId] === 'meal' || $kind[$componentId] === 'transport' ? $perDay += $line['amount_minor'] : $monthly += $line['amount_minor'];
            }

            $p = $profiles[$e['id']] ?? null;
            $people[] = [
                'id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department'], 'position' => $e['position'],
                'pay' => (object) array_map(static fn (array $l): array => ['amount_minor' => $l['amount_minor'], 'effective_from' => $l['effective_from']], $current), 'monthly_minor' => $monthly, 'per_day_minor' => $perDay,
                'profile' => $p === null ? null : self::profile($p),
            ];
        }

        $history = [];

        if ($employeeId !== null && $employeeId !== '') {
            $history = array_reverse(array_map(static fn (array $i): array => ['component_id' => $i['component_id'], 'amount_minor' => (int) $i['amount_minor'], 'effective_from' => $i['effective_from'], 'reason' => $i['reason']], $this->store->items($property, strtolower($employeeId))));
        }

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $today, 'earliest' => substr($today, 0, 8).'01', 'components' => $components, 'employees' => $people, 'history' => $history, 'selected' => $employeeId === '' ? null : $employeeId,
            'settings' => $this->settings->current($property), 'statuses' => PayrollSettingsService::STATUSES,
        ];
    }

    /** @return array<string, mixed> */
    public function setPay(PropertyId $property, string $actorId, string $employeeId, string $componentId, int $amountMinor, string $effectiveFrom, string $reason): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set what people earn.');
        $reason = trim($reason);
        $today = $this->businessDate->current($property)->toString();

        if (! ShiftTimes::isDate($effectiveFrom) || $effectiveFrom < substr($today, 0, 8).'01') {
            throw Refusal::invalid('The line starts on a date from the first day of this month.', ['effective_from']);
        }

        if ($amountMinor < 0 || $amountMinor > 999_999_999_999) {
            throw Refusal::invalid('Give an amount of 0 or more.', ['amount_minor']);
        }

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);
        $employeeId = strtolower($employeeId);
        $componentId = strtolower($componentId);

        $this->transactions->run(function () use ($property, $actor, $employeeId, $componentId, $amountMinor, $effectiveFrom, $reason, $today): void {
            $employee = $this->employees->employee($property, $employeeId) ?? throw Refusal::notFound('Employee not found.');
            $component = $this->store->component($property, $componentId) ?? throw Refusal::notFound('Kind of earning not found.');

            if ($employee['status'] !== 'active') {
                throw Refusal::stateConflict('This person has left; nothing more is set for them.');
            }

            if (! (bool) $component['is_active']) {
                throw Refusal::stateConflict('This kind of earning is retired.');
            }

            $before = self::inForce(array_values(array_filter($this->store->items($property, $employeeId), static fn (array $i): bool => $i['component_id'] === $componentId)), $today)[$componentId]['amount_minor'] ?? null;

            if (! $this->store->addItem($property, ['id' => $this->ids->next(), 'employee_id' => $employeeId, 'component_id' => $componentId, 'amount_minor' => $amountMinor, 'effective_from' => $effectiveFrom, 'reason' => $reason, 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This person has a line of this kind from this date already. Choose a later date.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'pay_item.set', 'hr_employee', $employeeId, ['amount_minor' => $before], ['component' => $component['code'], 'amount_minor' => $amountMinor, 'effective_from' => $effectiveFrom], $reason));
        });

        return $this->overview($property, $actorId, $employeeId);
    }

    /** @return array<string, mixed> */
    public function saveProfile(PropertyId $property, string $actorId, string $employeeId, string $ptkp, bool $hasNpwp, bool $inHealth, bool $inEmployment, ?int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set the tax of people.');

        if (! in_array($ptkp, PayrollSettingsService::STATUSES, true)) {
            throw Refusal::invalid('Choose a tax status of the list.', ['ptkp_status']);
        }

        $actor = strtolower($actorId);
        $employeeId = strtolower($employeeId);
        $values = ['ptkp_status' => $ptkp, 'has_npwp' => $hasNpwp, 'in_health' => $inHealth, 'in_employment' => $inEmployment];

        $this->transactions->run(function () use ($property, $actor, $employeeId, $values, $lock): void {
            $employee = $this->employees->employee($property, $employeeId) ?? throw Refusal::notFound('Employee not found.');
            $before = $this->store->profile($property, $employeeId);

            if (($before === null ? null : (int) $before['lock_version']) !== $lock || ! $this->store->saveProfile($property, $employeeId, $values, $lock, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The tax data of this person changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'pay_profile.saved', 'hr_employee', $employee['id'], $before === null ? null : self::profile($before), $values));
        });

        return self::profile($this->store->profile($property, $employeeId) ?? throw Refusal::notFound('Employee not found.'));
    }

    /** The amount of each component in force on a day. @param list<array<string, mixed>> $lines  @return array<string, array{amount_minor: int, effective_from: string}> */
    public static function inForce(array $lines, string $day): array
    {
        $out = [];

        foreach ($lines as $l) {
            if ($l['effective_from'] <= $day && (! isset($out[$l['component_id']]) || $l['effective_from'] >= $out[$l['component_id']]['effective_from'])) {
                $out[$l['component_id']] = ['amount_minor' => (int) $l['amount_minor'], 'effective_from' => (string) $l['effective_from']];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function profile(array $p): array
    {
        return ['ptkp_status' => $p['ptkp_status'], 'has_npwp' => (bool) $p['has_npwp'], 'in_health' => (bool) $p['in_health'], 'in_employment' => (bool) $p['in_employment'], 'lock_version' => isset($p['lock_version']) ? (int) $p['lock_version'] : null];
    }
}
