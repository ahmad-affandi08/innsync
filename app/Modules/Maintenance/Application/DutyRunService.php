<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The times the routine duties fall due (FR-MTC-011). Each business day the duties that fall due make a run, with the steps they have at that moment; a technician (or a manager)
 * checks each step as fine, with an issue, or not applicable, and finishes the run when every step is answered. An issue raises a work order at once, for the asset or the place of the
 * duty, so a finding is never only a tick. A run that is not finished by the end of its business day is missed, and stays on record for the managers; a run that was made, done or
 * missed, is never changed afterwards. Runs are made by the scheduler and when the screen is opened, so a day nobody looked at is still counted.
 */
final readonly class DutyRunService
{
    public const RESULTS = ['ok', 'issue', 'na'];

    private const CATCH_UP_DAYS = 31;

    public function __construct(
        private DutyStore $duties,
        private WorkOrderService $workOrders,
        private MaintenanceAccess $access,
        private BusinessDateProvider $businessDate,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** Makes the runs of the duties that fell due up to the business day, and marks the ones that were not done in time as missed. @return int how many runs were made */
    public function generate(PropertyId $property): int
    {
        $today = $this->businessDate->current($property)->toString();
        $made = 0;
        $now = $this->clock->nowUtc();

        foreach ($this->duties->duties($property, true) as $d) {
            $last = $this->duties->lastDue($property, $d['id']);
            $from = max(substr((string) $d['starts_on'], 0, 10), $last === null ? '0000-00-00' : date('Y-m-d', strtotime($last.' +1 day')), date('Y-m-d', strtotime($today.' -'.(self::CATCH_UP_DAYS - 1).' days')));

            for ($day = $from; $day <= $today; $day = date('Y-m-d', strtotime($day.' +1 day'))) {
                if (! $this->falls($d, $day)) {
                    continue;
                }

                $made += $this->duties->addRun($property, [
                    'id' => $this->ids->next(), 'duty_id' => $d['id'], 'title' => $d['title'], 'shift' => $d['shift'], 'asset_id' => $d['asset_id'], 'area' => $d['area'], 'category' => $d['category'], 'due_on' => $day,
                ], $d['steps'], $now) ? 1 : 0;
            }
        }

        $this->duties->markMissed($property, $today, $now);

        return $made;
    }

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $caps = $this->capabilities($property, $actorId);
        $this->generate($property);
        $today = $this->businessDate->current($property)->toString();
        $from = $from === null || $from === '' ? date('Y-m-d', strtotime($today.' -6 days')) : $from;
        $to = $to === null || $to === '' ? $today : $to;

        if (! $this->isDate($from) || ! $this->isDate($to) || $to < $from || strtotime($to) - strtotime($from) > 92 * 86400) {
            throw Refusal::invalid('Choose a period of at most three months, ending after it starts.', ['from', 'to']);
        }

        $rows = $this->duties->runs($property, null, $from, $to, 500);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter(array_column($rows, 'done_by')))));
        $counts = ['open' => 0, 'done' => 0, 'missed' => 0];

        foreach ($rows as $r) {
            $counts[$r['status']]++;
        }

        return [
            'business_date' => $today, 'from' => $from, 'to' => $to, 'counts' => $counts,
            'runs' => array_map(fn (array $r): array => $this->head($r, $names), $rows),
            'may' => ['do' => $caps['do'], 'manage' => $caps['manage']],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $caps = $this->capabilities($property, $actorId);
        $run = $this->duties->run($property, strtolower($id)) ?? throw Refusal::notFound('Duty run not found.');
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([$run['done_by'], ...array_column($run['steps'], 'checked_by')]))));
        $open = $run['status'] === 'open';

        return [
            ...$this->head([...$run, 'step_count' => count($run['steps']), 'pending_count' => count(array_filter($run['steps'], static fn (array $s): bool => $s['result'] === 'pending')), 'issue_count' => count(array_filter($run['steps'], static fn (array $s): bool => $s['result'] === 'issue'))], $names),
            'steps' => array_map(fn (array $s): array => ['id' => $s['id'], 'position' => (int) $s['position'], 'text' => $s['text'], 'result' => $s['result'], 'note' => $s['note'], 'work_order_id' => $s['work_order_id'], 'by' => $names[$s['checked_by'] ?? ''] ?? null, 'at' => $this->utc($s['checked_at'])], $run['steps']),
            'note' => $run['note'], 'may' => ['do' => $caps['do'] && $open],
        ];
    }

    /** @return array<string, mixed> */
    public function check(PropertyId $property, string $actorId, string $runId, string $stepId, string $result, ?string $note): array
    {
        $this->requireDo($property, $actorId);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! in_array($result, self::RESULTS, true)) {
            throw Refusal::invalid('Choose fine, issue or not applicable.', ['result']);
        }

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        if ($result === 'issue' && $note === null) {
            throw Refusal::invalid('Say what is wrong.', ['note']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $actorId, $runId, $stepId, $result, $note): void {
            $this->duties->lockRun($property, strtolower($runId));
            $run = $this->duties->run($property, strtolower($runId)) ?? throw Refusal::notFound('Duty run not found.');

            if ($run['status'] !== 'open') {
                throw Refusal::stateConflict($run['status'] === 'done' ? 'This duty is done.' : 'This duty was missed and is closed.');
            }

            $step = null;

            foreach ($run['steps'] as $s) {
                if ($s['id'] === strtolower($stepId)) {
                    $step = $s;
                }
            }

            $step ?? throw Refusal::notFound('Step not found.');
            $workOrder = $step['work_order_id'];

            // A finding raises a work order, once for the step however often its answer is changed.
            if ($result === 'issue' && $workOrder === null) {
                $made = $this->workOrders->report($property, $actorId, mb_substr("{$run['title']}: {$step['text']}", 0, 80), mb_substr("{$run['title']} of {$run['due_on']}, step {$step['position']}: {$note}", 0, 500), $run['category'], 'engineering', null, $run['area'], 'normal', null, null, $run['asset_id']);
                $workOrder = $made['work_order']['id'];
            }

            $this->duties->updateStep($property, $run['id'], $step['id'], ['result' => $result, 'note' => $note, 'work_order_id' => $workOrder, 'checked_by' => $actor, 'checked_at' => $this->clock->nowUtc()]);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'duty_run.step_checked', 'duty_run', $run['id'], ['result' => $step['result']], ['result' => $result, 'step' => $step['position'], 'duty' => $run['title'], 'work_order' => $workOrder], $note));
        });

        return $this->show($property, $actorId, $runId);
    }

    /** @return array<string, mixed> */
    public function complete(PropertyId $property, string $actorId, string $runId, ?string $note, int $lock): array
    {
        $this->requireDo($property, $actorId);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('The note is at most 300 characters.', ['note']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $runId, $note, $lock): void {
            $this->duties->lockRun($property, strtolower($runId));
            $run = $this->duties->run($property, strtolower($runId)) ?? throw Refusal::notFound('Duty run not found.');

            if ($run['status'] !== 'open') {
                throw Refusal::stateConflict($run['status'] === 'done' ? 'This duty is done already.' : 'This duty was missed and is closed.');
            }

            if ((int) $run['lock_version'] !== $lock) {
                throw Refusal::stateConflict('This duty changed after you opened it. Reload it.');
            }

            $pending = count(array_filter($run['steps'], static fn (array $s): bool => $s['result'] === 'pending'));

            if ($pending > 0) {
                throw Refusal::invalid("{$pending} steps are not answered yet.", ['steps']);
            }

            $now = $this->clock->nowUtc();

            if (! $this->duties->updateRun($property, $run['id'], $lock, ['status' => 'done', 'done_by' => $actor, 'done_at' => $now, 'note' => $note], $now)) {
                throw Refusal::stateConflict('This duty changed after you opened it. Reload it.');
            }

            $issues = count(array_filter($run['steps'], static fn (array $s): bool => $s['result'] === 'issue'));
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'duty_run.done', 'duty_run', $run['id'], ['status' => 'open'], ['status' => 'done', 'duty' => $run['title'], 'due_on' => substr((string) $run['due_on'], 0, 10), 'issues' => $issues], $note));
            // Told to Human Resource like a checklist that was done (FR-HR-020): who did the round and how many steps it had.
            $this->outbox->publish(new OutboxEvent($property, 'maintenance.duty.completed', $run['id'], 1, [
                'run_id' => $run['id'], 'checklist' => $run['title'], 'frequency' => 'daily', 'period' => substr((string) $run['due_on'], 0, 10), 'total' => count($run['steps']), 'completed_by' => $actor, 'issues' => $issues,
            ]));
        });

        return $this->show($property, $actorId, $runId);
    }

    /**
     * What the duties did in a period, for the reports.
     *
     * @return array{runs: int, done: int, missed: int, open: int, issues: int, done_percent: int|null, by_duty: list<array{title: string, done: int, missed: int}>}
     */
    public function compliance(PropertyId $property, string $from, string $to): array
    {
        $rows = $this->duties->runs($property, null, $from, $to, 5000);
        $by = [];
        $c = ['done' => 0, 'missed' => 0, 'open' => 0];
        $issues = 0;

        foreach ($rows as $r) {
            $c[$r['status']]++;
            $issues += (int) $r['issue_count'];

            if ($r['status'] !== 'open') {
                $by[$r['title']][$r['status']] = ($by[$r['title']][$r['status']] ?? 0) + 1;
            }
        }

        $closed = $c['done'] + $c['missed'];
        uasort($by, static fn (array $a, array $b): int => ($b['missed'] ?? 0) <=> ($a['missed'] ?? 0));

        return [
            'runs' => count($rows), 'done' => $c['done'], 'missed' => $c['missed'], 'open' => $c['open'], 'issues' => $issues, 'done_percent' => $closed === 0 ? null : (int) round($c['done'] * 100 / $closed),
            'by_duty' => array_values(array_map(static fn (string $t, array $v): array => ['title' => $t, 'done' => $v['done'] ?? 0, 'missed' => $v['missed'] ?? 0], array_keys($by), array_values($by))),
        ];
    }

    /** @param array<string, mixed> $duty */
    private function falls(array $duty, string $day): bool
    {
        return match ($duty['frequency']) {
            'daily' => true,
            'weekly' => (int) date('N', strtotime($day)) === (int) $duty['weekday'],
            'monthly' => (int) date('j', strtotime($day)) === (int) $duty['month_day'],
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $r, array $names): array
    {
        return [
            'id' => $r['id'], 'title' => $r['title'], 'shift' => $r['shift'], 'area' => $r['area'], 'asset_id' => $r['asset_id'], 'category' => $r['category'], 'due_on' => substr((string) $r['due_on'], 0, 10), 'status' => $r['status'],
            'steps' => (int) $r['step_count'], 'pending' => (int) $r['pending_count'], 'issues' => (int) $r['issue_count'], 'done_by' => $names[$r['done_by'] ?? ''] ?? null, 'done_at' => $this->utc($r['done_at']), 'lock_version' => (int) $r['lock_version'],
        ];
    }

    /** @return array{do: bool, manage: bool} */
    private function capabilities(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $manage = $this->access->may($property, $actorId, MaintenanceAccess::MANAGE);
        $do = $manage || $this->access->may($property, $actorId, MaintenanceAccess::PERFORM);

        if (! $do) {
            throw Refusal::forbidden('This person may not see the routine duties.');
        }

        return ['do' => $do, 'manage' => $manage];
    }

    private function requireDo(PropertyId $property, string $actorId): void
    {
        $this->capabilities($property, $actorId);
    }

    private function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private function utc(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
