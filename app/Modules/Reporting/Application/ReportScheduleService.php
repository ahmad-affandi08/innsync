<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Reporting\Domain\ReportScheduleCalendar;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Reports that are built by themselves at a set time for chosen people (FR-RPT-004). A schedule names a report that can be exported in the background, the period it covers relative to the day
 * it runs (today, yesterday, the last seven days, the month so far), how often (every day, a weekday of the week, a day of the month from 1 to 28) and at what time on the property's clock, and the
 * recipients. At each run the export of every recipient is asked for and built as that person with their own rights and audit, so the schedule never hands out more than the person could
 * download; a recipient who has left or may no longer export the report is skipped and the run says so. The recipient finds the file on the exports page; when the schedule says so an e-mail
 * tells them it is ready (it carries no figures, only where to look). A run missed because the worker was down runs once when it is back, not once for each missed time. No instant
 * message channel is built, because no provider is chosen.
 */
final readonly class ReportScheduleService
{
    public const MANAGE_PERMISSION = 'reporting.schedule.manage';

    public const PRESETS = ['today', 'yesterday', 'last7', 'month'];

    public const MAX_RECIPIENTS = 20;

    public const MAX_SCHEDULES = 50;

    public function __construct(
        private ReportScheduleRepository $schedules,
        private ExportJobService $exports,
        private ReportService $reports,
        private StaffDirectory $staff,
        private PermissionChecker $permissions,
        private BusinessDateProvider $businessDate,
        private PropertyTimeZoneReader $zones,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);
        $manage = $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property);

        if (! $manage) {
            throw Refusal::forbidden('This person may not see the scheduled reports.');
        }

        $rows = $this->schedules->all($property);
        $ids = [];

        foreach ($rows as $s) {
            $ids = [...$ids, ...$s['recipients'], (string) $s['created_by']];
        }

        $names = $this->staff->namesOf($property, array_values(array_unique($ids)));
        $runs = [];

        foreach ($this->schedules->runs($property, 3) as $r) {
            $runs[$r['schedule_id']][] = ['ran_at' => self::utc($r['ran_at']), 'queued' => (int) $r['queued'], 'skipped' => (int) $r['skipped'], 'note' => $r['skipped_note']];
        }

        $zone = $this->zone($property);
        $reports = array_values(array_filter(array_keys(ExportJobService::REPORTS), fn (string $code): bool => $this->reports->mayExport($property, $actorId, $code)));

        return [
            'schedules' => array_map(static fn (array $s): array => [
                'id' => $s['id'], 'name' => $s['name'], 'report' => $s['report'], 'params' => $s['params'], 'cadence' => $s['cadence'], 'weekday' => $s['weekday'] === null ? null : (int) $s['weekday'], 'month_day' => $s['month_day'] === null ? null : (int) $s['month_day'],
                'at_time' => substr((string) $s['at_time'], 0, 5), 'notify_email' => (bool) $s['notify_email'], 'is_active' => (bool) $s['is_active'], 'next_run_at' => self::utc($s['next_run_at']),
                'last_run_at' => self::utc($s['last_run_at']), 'lock_version' => (int) $s['lock_version'], 'created_by' => $names[$s['created_by']] ?? null,
                'recipients' => array_map(static fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? null], $s['recipients']),
            ], $rows),
            'runs' => $runs, 'reports' => $reports, 'presets' => self::PRESETS, 'cadences' => ReportScheduleCalendar::CADENCES, 'time_zone' => $zone->identifier(),
            'members' => $this->staff->members($property), 'may' => ['manage' => true],
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  list<string>  $recipients
     * @return array<string, mixed>
     */
    public function create(PropertyId $property, string $actorId, array $in, array $recipients): array
    {
        $this->assertProperty($property);
        $this->requireManage($property, $actorId);
        $fields = $this->validated($property, $actorId, $in, $recipients);

        if (count($this->schedules->all($property)) >= self::MAX_SCHEDULES) {
            throw Refusal::stateConflict('There are '.self::MAX_SCHEDULES.' scheduled reports already. Switch one off or reuse it.');
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();
        $row = [...$fields['row'], 'id' => $id, 'is_active' => true, 'next_run_at' => $this->next($property, $fields['row'], $now), 'last_run_at' => null, 'created_by' => $actor];

        $this->transactions->run(function () use ($property, $actor, $row, $fields, $now): void {
            $this->schedules->add($property, $row, $fields['recipients'], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'report_schedule.created', 'report_schedule', $row['id'], null, ['name' => $row['name'], 'report' => $row['report'], 'cadence' => $row['cadence'], 'at_time' => $row['at_time'], 'recipients' => count($fields['recipients'])]));
        });

        return $this->overview($property, $actorId);
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  list<string>  $recipients
     * @return array<string, mixed>
     */
    public function update(PropertyId $property, string $actorId, string $id, int $lock, array $in, array $recipients): array
    {
        $this->assertProperty($property);
        $this->requireManage($property, $actorId);
        $schedule = $this->schedules->find($property, strtolower($id)) ?? throw Refusal::notFound('Schedule not found.');
        $fields = $this->validated($property, $actorId, $in, $recipients);
        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();
        $changes = [...$fields['row'], 'params' => $fields['row']['params'], 'next_run_at' => (bool) $schedule['is_active'] ? $this->next($property, $fields['row'], $now) : null];

        $this->transactions->run(function () use ($property, $actor, $schedule, $lock, $changes, $fields, $now): void {
            if (! $this->schedules->update($property, $schedule['id'], $lock, $changes, $fields['recipients'], $now)) {
                throw Refusal::stateConflict('This schedule was changed by someone else. Reload it and check it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'report_schedule.updated', 'report_schedule', $schedule['id'], ['name' => $schedule['name'], 'cadence' => $schedule['cadence'], 'at_time' => substr((string) $schedule['at_time'], 0, 5), 'recipients' => count($schedule['recipients'])], ['name' => $changes['name'], 'cadence' => $changes['cadence'], 'at_time' => $changes['at_time'], 'recipients' => count($fields['recipients'])]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function setActive(PropertyId $property, string $actorId, string $id, int $lock, bool $active): array
    {
        $this->assertProperty($property);
        $this->requireManage($property, $actorId);
        $schedule = $this->schedules->find($property, strtolower($id)) ?? throw Refusal::notFound('Schedule not found.');
        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();
        $next = $active ? $this->next($property, $schedule, $now) : null;

        $this->transactions->run(function () use ($property, $actor, $schedule, $lock, $active, $next, $now): void {
            if (! $this->schedules->update($property, $schedule['id'], $lock, ['is_active' => $active, 'next_run_at' => $next], null, $now)) {
                throw Refusal::stateConflict('This schedule was changed by someone else. Reload it and check it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'report_schedule.resumed' : 'report_schedule.paused', 'report_schedule', $schedule['id'], ['is_active' => ! $active], ['is_active' => $active]));
        });

        return $this->overview($property, $actorId);
    }

    /**
     * Runs the schedules of the property whose time has come. Called by the scheduled runner.
     *
     * @return int how many schedules ran
     */
    public function runDue(PropertyId $property, int $max = 20): int
    {
        $this->assertProperty($property);
        $ran = 0;
        $now = $this->clock->nowUtc();

        foreach ($this->schedules->due($property, $now, $max) as $due) {
            $this->transactions->run(function () use ($property, $due, $now): void {
                $this->schedules->lock($property, $due['id']);
                $schedule = $this->schedules->find($property, $due['id']);

                if ($schedule === null || ! (bool) $schedule['is_active'] || $schedule['next_run_at'] === null || new DateTimeImmutable((string) $schedule['next_run_at'], new DateTimeZone('UTC')) > $now) {
                    return;
                }

                $this->run($property, $schedule, $now);
            });
            $ran++;
        }

        return $ran;
    }

    /** @param array<string, mixed> $schedule */
    private function run(PropertyId $property, array $schedule, DateTimeImmutable $now): void
    {
        $members = array_column($this->staff->members($property), 'id');
        $purpose = mb_substr('Scheduled report: '.$schedule['name'], 0, 300);
        $queued = 0;
        $skipped = [];

        foreach ($schedule['recipients'] as $recipient) {
            if (! in_array($recipient, $members, true)) {
                $skipped[] = 'a recipient no longer works here';

                continue;
            }

            if (! $this->reports->mayExport($property, $recipient, $schedule['report'])) {
                $skipped[] = 'a recipient may no longer export this report';

                continue;
            }

            try {
                $this->exports->request($property, $recipient, $schedule['report'], $this->runtimeParams($property, $schedule), $purpose, $schedule['id']);
                $queued++;
            } catch (Refusal $e) {
                $skipped[] = mb_substr($e->getMessage(), 0, 80);
            }
        }

        $this->schedules->addRun($property, ['id' => $this->ids->next(), 'schedule_id' => $schedule['id'], 'due_at' => $schedule['next_run_at'], 'ran_at' => $now, 'queued' => $queued, 'skipped' => count($skipped), 'skipped_note' => $skipped === [] ? null : mb_substr(implode('; ', array_unique($skipped)), 0, 300)]);
        $this->schedules->ran($property, $schedule['id'], $now, $this->next($property, $schedule, $now));
        $this->audit->record(new AuditEntry($property->toString(), (string) $schedule['created_by'], 'report_schedule.ran', 'report_schedule', $schedule['id'], null, ['name' => $schedule['name'], 'queued' => $queued, 'skipped' => count($skipped)]));
    }

    /**
     * The inputs of the export a run asks for: the period names are the ones of the exports page, and the movements report takes a date.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    private function runtimeParams(PropertyId $property, array $schedule): array
    {
        $params = $schedule['params'];

        if ($schedule['report'] === 'movements') {
            $today = $this->businessDate->current($property);
            $date = ($params['preset'] ?? 'today') === 'yesterday' ? $today->previous() : $today;

            return ['date' => $date->toString()];
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  list<string>  $recipients
     * @return array{row: array<string, mixed>, recipients: list<string>}
     */
    private function validated(PropertyId $property, string $actorId, array $in, array $recipients): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $report = (string) ($in['report'] ?? '');
        $cadence = (string) ($in['cadence'] ?? '');

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Name the schedule, in at most 80 characters.', ['name']);
        }

        if (! isset(ExportJobService::REPORTS[$report])) {
            throw Refusal::invalid('Choose a report that can be built in the background.', ['report']);
        }

        if (! $this->reports->mayExport($property, $actorId, $report)) {
            throw Refusal::forbidden('This person may not export this report, so cannot schedule it.');
        }

        if (! in_array($cadence, ReportScheduleCalendar::CADENCES, true)) {
            throw Refusal::invalid('Choose daily, weekly or monthly.', ['cadence']);
        }

        $weekday = $cadence === 'weekly' ? (int) ($in['weekday'] ?? 0) : null;
        $monthDay = $cadence === 'monthly' ? (int) ($in['month_day'] ?? 0) : null;
        $atTime = (string) ($in['at_time'] ?? '');

        try {
            ReportScheduleCalendar::next($cadence, $weekday, $monthDay, $atTime, $this->zone($property), $this->clock->nowUtc());
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), [$cadence === 'weekly' ? 'weekday' : ($cadence === 'monthly' ? 'month_day' : 'at_time')]);
        }

        $params = $this->params($report, $in['params'] ?? []);
        $recipients = array_values(array_unique(array_map('strtolower', $recipients)));

        if ($recipients === [] || count($recipients) > self::MAX_RECIPIENTS) {
            throw Refusal::invalid('Choose between 1 and '.self::MAX_RECIPIENTS.' recipients.', ['recipients']);
        }

        $members = $this->staff->members($property);
        $named = array_column($members, 'name', 'id');

        foreach ($recipients as $r) {
            if (! isset($named[$r])) {
                throw Refusal::invalid('Choose recipients who work in this property.', ['recipients']);
            }

            if (! $this->reports->mayExport($property, $r, $report)) {
                throw Refusal::invalid($named[$r].' may not export this report, so cannot receive it.', ['recipients']);
            }
        }

        return [
            'row' => ['name' => $name, 'report' => $report, 'params' => $params, 'cadence' => $cadence, 'weekday' => $weekday, 'month_day' => $monthDay, 'at_time' => substr($atTime, 0, 5).':00', 'notify_email' => (bool) ($in['notify_email'] ?? false)],
            'recipients' => $recipients,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function params(string $report, mixed $given): array
    {
        $p = is_array($given) ? $given : [];
        $preset = (string) ($p['preset'] ?? 'yesterday');

        return match ($report) {
            'movements' => in_array($preset, ['today', 'yesterday'], true) ? ['preset' => $preset] : throw Refusal::invalid('Choose today or yesterday.', ['params']),
            'comparison' => in_array($p['kind'] ?? 'day', ['day', 'month', 'year'], true) ? ['kind' => (string) ($p['kind'] ?? 'day')] : throw Refusal::invalid('Choose day, month or year.', ['params']),
            'performance' => (static function () use ($p, $preset): array {
                $by = (string) ($p['by'] ?? 'day');

                if (! in_array($by, ['day', 'month', 'year'], true) || ! in_array($preset, self::PRESETS, true)) {
                    throw Refusal::invalid('Choose day, month or year, and a period.', ['params']);
                }

                return $by === 'day' ? ['by' => 'day', 'preset' => $preset] : ['by' => $by];
            })(),
            default => in_array($preset, self::PRESETS, true) ? ['preset' => $preset] : throw Refusal::invalid('Choose a period.', ['params']),
        };
    }

    /** @param array<string, mixed> $s */
    private function next(PropertyId $property, array $s, DateTimeImmutable $after): DateTimeImmutable
    {
        return ReportScheduleCalendar::next((string) $s['cadence'], $s['weekday'] === null ? null : (int) $s['weekday'], $s['month_day'] === null ? null : (int) $s['month_day'], (string) $s['at_time'], $this->zone($property), $after);
    }

    private static function utc(mixed $value): ?string
    {
        return $value === null ? null : str_replace(' ', 'T', substr((string) $value, 0, 19)).'Z';
    }

    private function zone(PropertyId $property): PropertyTimeZone
    {
        return $this->zones->forProperty($property) ?? PropertyTimeZone::fromIdentifier('UTC');
    }

    private function requireManage(PropertyId $property, string $actorId): void
    {
        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not schedule reports.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->context->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
