<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Two people exchange their shifts of one day (FR-HR-017). A person asks a colleague of the same department, whom they name; the colleague agrees or declines; then a supervisor decides: the supervisor of either of the two,
 * or whoever plans the roster, never one of the two themselves. The shifts as they were when asked are kept, so the roster is changed only if it is still as it was, and only for a day that has not begun. Approving exchanges
 * the two shifts in the roster; nothing else about the days changes, so the people needed on each shift stay the same.
 */
final readonly class ShiftSwapService
{
    private const FIELDS = ['pattern_id', 'pattern_code', 'is_off', 'starts_at', 'ends_at', 'starts2_at', 'ends2_at', 'minutes'];

    public function __construct(
        private ShiftSwapStore $store,
        private RosterStore $roster,
        private EmployeeStore $employees,
        private HrAccess $access,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();
        $me = $this->employees->employeeOfUser($property, $actor);
        $decides = $this->access->may($property, $actorId, HrAccess::ROSTER);
        $mine = $me === null ? [] : $this->store->forPerson($property, $me, 100);
        $supervised = [];

        foreach ($this->store->awaitingSupervisor($property, 200) as $s) {
            if ($this->mayDecide($property, $actorId, $s, $me)) {
                $supervised[] = $s;
            }
        }

        $colleagues = [];

        if ($me !== null) {
            $mine_ = $this->employees->employee($property, $me);

            foreach ($this->employees->employees($property, 'active') as $e) {
                if ($e['id'] !== $me && $e['department'] === ($mine_['department'] ?? null)) {
                    $colleagues[] = ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name']];
                }
            }
        }

        return [
            'today' => $today, 'linked' => $me !== null, 'me' => $me, 'may' => ['decide' => $decides], 'colleagues' => $colleagues,
            'mine' => array_map(fn (array $s): array => $this->shape($property, $actorId, $s, $me, $today), $mine), 'to_decide' => array_map(fn (array $s): array => $this->shape($property, $actorId, $s, $me, $today), $supervised),
        ];
    }

    /** @return array<string, mixed> */
    public function request(PropertyId $property, string $actorId, string $partnerId, string $date, string $reason): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        if (! ShiftTimes::isDate($date)) {
            throw Refusal::invalid('Give the day as year-month-day.', ['work_date']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $partnerId, $date, $reason, $id): void {
            $me = $this->employees->employeeOfUser($property, $actor) ?? throw Refusal::forbidden('Your account is not linked to an employee record.');
            $mine = $this->employees->employee($property, $me) ?? throw Refusal::notFound('Employee not found.');
            $partner = $this->employees->employee($property, strtolower($partnerId)) ?? throw Refusal::notFound('Employee not found.');

            if ($partner['id'] === $mine['id']) {
                throw Refusal::invalid('Choose a colleague, not yourself.', ['partner_id']);
            }

            if ($mine['status'] !== 'active' || $partner['status'] !== 'active') {
                throw Refusal::stateConflict('Both people must still work here.');
            }

            if ($mine['department'] !== $partner['department']) {
                throw Refusal::stateConflict('Shifts are exchanged within a department.');
            }

            if ($date <= $this->businessDate->current($property)->toString()) {
                throw Refusal::invalid('Choose a day after today.', ['work_date']);
            }

            $a = $this->roster->entry($property, $mine['id'], $date);
            $b = $this->roster->entry($property, $partner['id'], $date);

            if ($a === null || $b === null) {
                throw Refusal::stateConflict('Both people must have a shift planned on that day.');
            }

            if ($a['pattern_id'] === $b['pattern_id']) {
                throw Refusal::stateConflict('Both have the same shift on that day; there is nothing to exchange.');
            }

            if ($this->store->openOn($property, $mine['id'], $date) || $this->store->openOn($property, $partner['id'], $date)) {
                throw Refusal::stateConflict('One of the two is in another exchange for that day that is still open.');
            }

            $this->store->add($property, ['id' => $id, 'requester_id' => $mine['id'], 'partner_id' => $partner['id'], 'work_date' => $date, 'requester_pattern_id' => $a['pattern_id'], 'requester_code' => $a['pattern_code'], 'partner_pattern_id' => $b['pattern_id'], 'partner_code' => $b['pattern_code'],
                'reason' => $reason, 'status' => 'awaiting_partner', 'requested_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'shift_swap.requested', 'shift_swap', $id, null, ['requester' => $mine['number'], 'partner' => $partner['number'], 'date' => $date, 'from' => $a['pattern_code'], 'to' => $b['pattern_code']], $reason));
        });

        return $this->one($property, $actorId, $id);
    }

    /** The colleague agrees or declines. @return array<string, mixed> */
    public function respond(PropertyId $property, string $actorId, string $id, bool $agree): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $agree): void {
            $s = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Exchange not found.');

            if ($s['status'] !== 'awaiting_partner') {
                throw Refusal::stateConflict('This exchange is not waiting for the colleague.');
            }

            if ($s['partner_user'] === null || strtolower((string) $s['partner_user']) !== $actor) {
                throw Refusal::forbidden('Only the colleague who was asked answers.');
            }

            $now = $this->clock->nowUtc();
            $status = $agree ? 'awaiting_supervisor' : 'declined';

            if (! $this->store->update($property, $s['id'], (int) $s['lock_version'], ['status' => $status, 'partner_decided_by' => $actor, 'partner_decided_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This exchange changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $agree ? 'shift_swap.accepted' : 'shift_swap.declined', 'shift_swap', $s['id'], ['status' => 'awaiting_partner'], ['status' => $status, 'date' => substr((string) $s['work_date'], 0, 10)]));
        });

        return $this->one($property, $actorId, $id);
    }

    /** A supervisor approves (the shifts are exchanged) or rejects. @return array<string, mixed> */
    public function decide(PropertyId $property, string $actorId, string $id, bool $approve, ?string $note): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $note = $note === null ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        if (! $approve && ($note === null || $note === '')) {
            throw Refusal::invalid('Say why it is rejected.', ['note']);
        }

        $this->transactions->run(function () use ($property, $actor, $actorId, $id, $approve, $note): void {
            $s = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Exchange not found.');

            if ($s['status'] !== 'awaiting_supervisor') {
                throw Refusal::stateConflict('This exchange is not waiting for a supervisor.');
            }

            if (! $this->mayDecide($property, $actorId, $s, $this->employees->employeeOfUser($property, $actor))) {
                throw Refusal::forbidden('Only a supervisor of one of the two, or whoever plans the roster, decides, and not one of the two themselves.');
            }

            $date = substr((string) $s['work_date'], 0, 10);
            $now = $this->clock->nowUtc();

            if ($approve) {
                if ($date <= $this->businessDate->current($property)->toString()) {
                    throw Refusal::stateConflict('The day has begun; the exchange can no longer be made.');
                }

                $this->employees->lockEmployee($property, $s['requester_id']);
                $this->employees->lockEmployee($property, $s['partner_id']);
                $a = $this->roster->entry($property, $s['requester_id'], $date);
                $b = $this->roster->entry($property, $s['partner_id'], $date);

                if ($a === null || $b === null || $a['pattern_id'] !== $s['requester_pattern_id'] || $b['pattern_id'] !== $s['partner_pattern_id']) {
                    throw Refusal::stateConflict('The roster of that day changed since the exchange was asked. Ask again.');
                }

                $this->roster->putEntry($property, ['employee_id' => $a['employee_id'], 'work_date' => $date, ...array_intersect_key($b, array_flip(self::FIELDS)), 'planned_by' => $actor], $now);
                $this->roster->putEntry($property, ['employee_id' => $b['employee_id'], 'work_date' => $date, ...array_intersect_key($a, array_flip(self::FIELDS)), 'planned_by' => $actor], $now);
            }

            $status = $approve ? 'approved' : 'rejected';

            if (! $this->store->update($property, $s['id'], (int) $s['lock_version'], ['status' => $status, 'decided_by' => $actor, 'decided_at' => $now->format('Y-m-d H:i:s.u'), 'decision_note' => $note === '' ? null : $note], $now)) {
                throw Refusal::stateConflict('This exchange changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $approve ? 'shift_swap.approved' : 'shift_swap.rejected', 'shift_swap', $s['id'], ['status' => 'awaiting_supervisor'], ['status' => $status, 'requester' => $s['requester_number'], 'partner' => $s['partner_number'], 'date' => $date], $note));

            if ($approve) {
                $this->outbox->publish(new OutboxEvent($property, 'hr.shift.swapped', $s['id'], 1, ['swap_id' => $s['id'], 'date' => $date, 'requester_id' => $s['requester_id'], 'partner_id' => $s['partner_id'], 'requester_code' => $s['requester_code'], 'partner_code' => $s['partner_code']]));
            }
        });

        return $this->one($property, $actorId, $id);
    }

    /** The person who asked withdraws it, until it is approved. @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id): void {
            $s = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Exchange not found.');

            if (! in_array($s['status'], ['awaiting_partner', 'awaiting_supervisor'], true)) {
                throw Refusal::stateConflict('Only an exchange that is still open is withdrawn.');
            }

            if ($s['requested_by'] !== $actor) {
                throw Refusal::forbidden('Only the person who asked withdraws it.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->update($property, $s['id'], (int) $s['lock_version'], ['status' => 'cancelled'], $now)) {
                throw Refusal::stateConflict('This exchange changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'shift_swap.cancelled', 'shift_swap', $s['id'], ['status' => $s['status']], ['status' => 'cancelled', 'date' => substr((string) $s['work_date'], 0, 10)]));
        });

        return $this->one($property, $actorId, $id);
    }

    /** @param array<string, mixed> $s */
    private function mayDecide(PropertyId $property, string $actorId, array $s, ?string $me): bool
    {
        $actor = strtolower($actorId);
        $own = ($s['requester_user'] !== null && strtolower((string) $s['requester_user']) === $actor) || ($s['partner_user'] !== null && strtolower((string) $s['partner_user']) === $actor);

        if ($own) {
            return false;
        }

        if ($this->access->may($property, $actorId, HrAccess::ROSTER)) {
            return true;
        }

        return $me !== null && ($s['requester_supervisor'] === $me || $s['partner_supervisor'] === $me);
    }

    /** @return array<string, mixed> */
    private function one(PropertyId $property, string $actorId, string $id): array
    {
        $s = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Exchange not found.');

        return $this->shape($property, $actorId, $s, $this->employees->employeeOfUser($property, strtolower($actorId)), $this->businessDate->current($property)->toString());
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function shape(PropertyId $property, string $actorId, array $s, ?string $me, string $today): array
    {
        $actor = strtolower($actorId);
        $date = substr((string) $s['work_date'], 0, 10);

        return [
            'id' => $s['id'], 'date' => $date, 'status' => $s['status'], 'reason' => $s['reason'], 'decision_note' => $s['decision_note'], 'lock_version' => (int) $s['lock_version'],
            'requester' => ['id' => $s['requester_id'], 'number' => $s['requester_number'], 'name' => $s['requester_name'], 'shift' => $s['requester_code']], 'partner' => ['id' => $s['partner_id'], 'number' => $s['partner_number'], 'name' => $s['partner_name'], 'shift' => $s['partner_code']],
            'may' => [
                'respond' => $s['status'] === 'awaiting_partner' && $s['partner_user'] !== null && strtolower((string) $s['partner_user']) === $actor,
                'decide' => $s['status'] === 'awaiting_supervisor' && $date > $today && $this->mayDecide($property, $actorId, $s, $me),
                'cancel' => in_array($s['status'], ['awaiting_partner', 'awaiting_supervisor'], true) && $s['requested_by'] === $actor,
            ],
        ];
    }
}
