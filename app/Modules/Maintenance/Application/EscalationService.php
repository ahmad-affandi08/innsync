<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Work orders that near or pass their deadline are escalated (FR-MTC-013). When a work order has used the warning share of its time, the first level is raised; when it has used
 * all of it (or the share the owner sets), the second. How many levels a priority has, and who is told, is the matrix: an urgent or high work order has both levels, a normal or low
 * one only the first; in the day shift the first level goes to the supervisor and the second to the manager on duty, in the night shift both go to the manager on duty. Each level is
 * raised once, kept as a fact with who was to be told and in which shift, and stays on the screens of those people until one of them acknowledges it. A work order that is closed
 * takes its escalations off the screens. It runs from the scheduler every few minutes and again whenever a manager opens the page, so it does not depend on either.
 */
final readonly class EscalationService
{
    /** How many levels each priority has. */
    public const LEVELS = ['urgent' => 2, 'high' => 2, 'normal' => 1, 'low' => 1];

    private const OPEN = ['open', 'assigned', 'in_progress', 'on_hold'];

    public function __construct(
        private WorkOrderStore $store,
        private MaintenanceAccess $access,
        private PropertyTimeZoneReader $zones,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** Raises the levels that are due. @return int how many were raised */
    public function run(PropertyId $property): int
    {
        $settings = $this->store->settings($property);
        $warn = $settings['warn'] ?? WorkOrderService::BASELINE_ESCALATION['warn'];
        $escalate = $settings['escalate'] ?? WorkOrderService::BASELINE_ESCALATION['escalate'];
        $now = $this->clock->nowUtc();
        $shift = $this->shiftAt($property, $now, $settings['night_from'] ?? WorkOrderService::BASELINE_ESCALATION['night_from'], $settings['night_to'] ?? WorkOrderService::BASELINE_ESCALATION['night_to']);
        $raised = 0;

        foreach ($this->store->list($property, self::OPEN, null, 500) as $w) {
            $start = (new DateTimeImmutable((string) $w['reported_at'], new DateTimeZone('UTC')))->getTimestamp();
            $span = max(1, (new DateTimeImmutable((string) $w['due_at'], new DateTimeZone('UTC')))->getTimestamp() - $start);
            $used = (int) floor(($now->getTimestamp() - $start) * 100 / $span);

            foreach ([1 => $warn, 2 => $escalate] as $level => $percent) {
                if ($level > self::LEVELS[$w['priority']] || $used < $percent) {
                    continue;
                }

                $target = $shift === 'night' || $level === 2 ? 'mod' : 'supervisor';
                $row = ['id' => $this->ids->next(), 'work_order_id' => $w['id'], 'level' => $level, 'target' => $target, 'shift' => $shift];
                $this->transactions->run(function () use ($property, $row, $w, $now, $used, &$raised): void {
                    if ($this->store->addEscalation($property, $row, $now)) {
                        $raised++;
                        $this->audit->record(new AuditEntry($property->toString(), null, 'work_order.escalated', 'work_order', $w['id'], null, ['number' => $w['number'], 'level' => $row['level'], 'target' => $row['target'], 'shift' => $row['shift'], 'priority' => $w['priority'], 'used_percent' => $used]));
                    }
                });
            }
        }

        return $raised;
    }

    /** What is waiting for this person to look at: the escalations meant for the supervisor if they manage work orders, and for the manager on duty if they are one. @return array<string, mixed> */
    public function waitingFor(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $supervisor = $this->access->may($property, $actorId, MaintenanceAccess::MANAGE);
        $mod = $this->access->may($property, $actorId, MaintenanceAccess::ESCALATION_RECEIVE);

        if (! $supervisor && ! $mod) {
            return ['escalations' => []];
        }

        $now = $this->clock->nowUtc();

        return ['escalations' => array_values(array_map(static fn (array $e): array => [
            'id' => $e['id'], 'work_order_id' => $e['work_order_id'], 'number' => $e['number'], 'title' => $e['title'], 'priority' => $e['priority'], 'place' => $e['room_number'] !== null ? 'Room '.$e['room_number'] : $e['area'],
            'level' => (int) $e['level'], 'target' => $e['target'], 'shift' => $e['shift'], 'raised_at' => (new DateTimeImmutable((string) $e['raised_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'overdue' => (new DateTimeImmutable((string) $e['due_at'], new DateTimeZone('UTC'))) < $now, 'assigned' => $e['assigned_to'] !== null,
        ], array_filter($this->store->openEscalations($property), static fn (array $e): bool => ($e['target'] === 'supervisor' && $supervisor) || ($e['target'] === 'mod' && $mod))))];
    }

    public function acknowledge(PropertyId $property, string $actorId, string $escalationId, ?string $note): void
    {
        $this->access->assertProperty($property);
        $e = $this->store->escalation($property, strtolower($escalationId)) ?? throw Refusal::notFound('Escalation not found.');
        $permission = $e['target'] === 'mod' ? MaintenanceAccess::ESCALATION_RECEIVE : MaintenanceAccess::MANAGE;

        if (! $this->access->may($property, $actorId, $permission)) {
            throw Refusal::forbidden('This escalation is not for this person.');
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $this->transactions->run(function () use ($property, $actorId, $e, $note): void {
            if (! $this->store->acknowledgeEscalation($property, $e['id'], strtolower($actorId), $note, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This escalation was acknowledged already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'work_order.escalation_acknowledged', 'work_order', $e['work_order_id'], ['level' => (int) $e['level'], 'target' => $e['target']], ['acknowledged' => true], $note));
        });
    }

    /** The shift at a moment: night between the hours the property sets (they may cross midnight), in its own time zone. */
    private function shiftAt(PropertyId $property, DateTimeImmutable $at, int $from, int $to): string
    {
        $zone = $this->zones->forProperty($property);
        $hour = (int) ($zone === null ? $at : $zone->localize($at))->format('G');
        $night = $from === $to ? false : ($from < $to ? $hour >= $from && $hour < $to : $hour >= $from || $hour < $to);

        return $night ? 'night' : 'day';
    }
}
