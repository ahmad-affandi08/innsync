<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The kinds of leave (FR-HR-015, -016). The owner writes them: whether a kind comes out of a yearly balance and how many days a year, how many months of work come first, from how many days a paper is needed, and
 * whether it is paid (said to payroll, later). A kind is changed (with the version of the screen) or retired and brought back, never deleted; requests already made keep the kind as it was.
 */
final readonly class LeaveTypeService
{
    /** Annual leave: 12 working days after 12 months of work, as the law gives. Sick leave: a paper from the third day. A permit has no pay. Maternity leave: 3 months, with a paper. @var list<array{0: string, 1: string, 2: bool, 3: int, 4: int, 5: int|null, 6: bool}> */
    public const BASELINE = [
        ['AL', 'Annual leave', true, 12, 12, null, true],
        ['SL', 'Sick leave', false, 0, 0, 2, true],
        ['IZ', 'Permit', false, 0, 0, null, false],
        ['ML', 'Maternity leave', false, 0, 0, 0, true],
    ];

    public function __construct(
        private LeaveStore $store,
        private HrAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::LEAVE, 'This person may not see how leave is set.');

        return array_map(self::shape(...), $this->store->types($property, false));
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $code, string $name, bool $deducts, int $entitlement, int $eligibleMonths, ?int $evidenceAfter, bool $paid): array
    {
        $this->access->require($property, $actorId, HrAccess::LEAVE, 'This person may not set how leave is set.');
        $clean = $this->clean($code, $name, $deducts, $entitlement, $eligibleMonths, $evidenceAfter, $paid);
        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $clean): void {
            if (! $this->store->addType($property, ['id' => $id, ...$clean], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A kind of leave with this code exists already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave_type.created', 'leave_type', $id, null, $clean));
        });

        return self::shape($this->store->type($property, $id) ?? throw Refusal::notFound('Kind of leave not found.'));
    }

    /** The code and whether it comes out of a balance never change. @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, string $name, int $entitlement, int $eligibleMonths, ?int $evidenceAfter, bool $paid, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::LEAVE, 'This person may not set how leave is set.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $entitlement, $eligibleMonths, $evidenceAfter, $paid, $lock): void {
            $before = $this->store->type($property, strtolower($id)) ?? throw Refusal::notFound('Kind of leave not found.');
            $clean = $this->clean($before['code'], $name, (bool) $before['deducts_balance'], (bool) $before['deducts_balance'] ? $entitlement : 0, (bool) $before['deducts_balance'] ? $eligibleMonths : 0, $evidenceAfter, $paid);
            unset($clean['code'], $clean['deducts_balance']);

            if (! $this->store->updateType($property, $before['id'], $lock, $clean, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This kind of leave changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave_type.changed', 'leave_type', $before['id'], array_intersect_key($before, $clean), $clean + ['code' => $before['code']]));
        });

        return self::shape($this->store->type($property, strtolower($id)) ?? throw Refusal::notFound('Kind of leave not found.'));
    }

    /** @return array<string, mixed> */
    public function setActive(PropertyId $property, string $actorId, string $id, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::LEAVE, 'This person may not set how leave is set.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $active, $lock): void {
            $before = $this->store->type($property, strtolower($id)) ?? throw Refusal::notFound('Kind of leave not found.');

            if ((bool) $before['is_active'] === $active) {
                throw Refusal::stateConflict($active ? 'This kind of leave is in use already.' : 'This kind of leave is retired already.');
            }

            if (! $this->store->updateType($property, $before['id'], $lock, ['is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This kind of leave changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'leave_type.resumed' : 'leave_type.retired', 'leave_type', $before['id'], ['is_active' => (bool) $before['is_active']], ['is_active' => $active, 'code' => $before['code']]));
        });

        return self::shape($this->store->type($property, strtolower($id)) ?? throw Refusal::notFound('Kind of leave not found.'));
    }

    /** Writes the usual kinds when none was written yet. @return list<array<string, mixed>> */
    public function baseline(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::LEAVE, 'This person may not set how leave is set.');

        if ($this->store->types($property, false) !== []) {
            throw Refusal::stateConflict('Kinds of leave were written already.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor): void {
            $now = $this->clock->nowUtc();

            foreach (self::BASELINE as [$code, $name, $deducts, $days, $months, $evidence, $paid]) {
                $this->store->addType($property, ['id' => $this->ids->next(), ...$this->clean($code, $name, $deducts, $days, $months, $evidence, $paid)], $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave_type.baseline', 'leave_type', $property->toString(), null, ['codes' => array_column(self::BASELINE, 0)]));
        });

        return $this->list($property, $actorId);
    }

    /** @return array<string, mixed> */
    public static function shape(array $t): array
    {
        return [
            'id' => $t['id'], 'code' => $t['code'], 'name' => $t['name'], 'deducts_balance' => (bool) $t['deducts_balance'], 'entitlement_days' => (int) $t['entitlement_days'], 'eligible_after_months' => (int) $t['eligible_after_months'],
            'evidence_after_days' => $t['evidence_after_days'] === null ? null : (int) $t['evidence_after_days'], 'paid' => (bool) $t['paid'], 'active' => (bool) $t['is_active'], 'lock_version' => (int) $t['lock_version'],
        ];
    }

    /** @return array<string, mixed> */
    private function clean(string $code, string $name, bool $deducts, int $entitlement, int $eligibleMonths, ?int $evidenceAfter, bool $paid): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9]{1,8}$/D', $code) !== 1) {
            throw Refusal::invalid('The code is 1 to 8 letters and digits.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 60) {
            throw Refusal::invalid('Give a name of at most 60 characters.', ['name']);
        }

        if ($deducts && ($entitlement < 1 || $entitlement > 365)) {
            throw Refusal::invalid('Days a year are from 1 to 365.', ['entitlement_days']);
        }

        if ($eligibleMonths < 0 || $eligibleMonths > 60) {
            throw Refusal::invalid('Months of work before the right begins are from 0 to 60.', ['eligible_after_months']);
        }

        if ($evidenceAfter !== null && ($evidenceAfter < 0 || $evidenceAfter > 365)) {
            throw Refusal::invalid('A paper is needed after 0 to 365 days, or never.', ['evidence_after_days']);
        }

        return ['code' => $code, 'name' => $name, 'deducts_balance' => $deducts, 'entitlement_days' => $deducts ? $entitlement : 0, 'eligible_after_months' => $deducts ? $eligibleMonths : 0, 'evidence_after_days' => $evidenceAfter, 'paid' => $paid];
    }
}
