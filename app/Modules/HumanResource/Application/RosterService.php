<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The shifts and the roster (FR-HR-010, FR-HR-011). The owner configures the shifts the property works (morning, afternoon, night, split, day off, or others); a manager plans who works
 * which shift on which day, for a week or a month; each planned day keeps the times of the shift as it was when planned. A day already past is not planned again here. For each department
 * and shift the owner may say the fewest people it needs, and the roster warns of every coming day that has fewer. Only people still working, and only within their contract, are planned.
 */
final readonly class RosterService
{
    public const MAX_DAYS = 62;

    public const MAX_CELLS = 800;

    private const BASELINE = [
        ['P', 'Morning', false, '07:00', '15:00', null, null],
        ['S', 'Afternoon', false, '15:00', '23:00', null, null],
        ['M', 'Night', false, '23:00', '07:00', null, null],
        ['SP', 'Split', false, '07:00', '11:00', '17:00', '21:00'],
        ['L', 'Day off', true, null, null, null, null],
    ];

    public function __construct(
        private RosterStore $roster,
        private LeaveStore $leave,
        private EmployeeStore $employees,
        private HrAccess $access,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $department): array
    {
        $caps = $this->capabilities($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $from = $from === null || $from === '' ? DateMath::format('Y-m-d', 'monday this week', $today) : $from;
        $to = $to === null || $to === '' ? DateMath::format('Y-m-d', $from.' +6 days') : $to;
        $department = $department === '' ? null : $department;

        if (! $this->isDate($from) || ! $this->isDate($to) || $to < $from || DateMath::daysBetween($from, $to) >= self::MAX_DAYS) {
            throw Refusal::invalid('Choose a period of at most '.self::MAX_DAYS.' days, ending after it starts.', ['from', 'to']);
        }

        if ($department !== null && ! in_array($department, EmployeeService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department of the list.', ['department']);
        }

        $days = [];

        for ($d = $from; $d <= $to; $d = DateMath::format('Y-m-d', $d.' +1 day')) {
            $days[] = $d;
        }

        $people = array_values(array_filter($this->employees->employees($property, 'active'), static fn (array $e): bool => $department === null || $e['department'] === $department));
        $cells = [];

        foreach ($this->roster->entriesBetween($property, $from, $to, $department) as $e) {
            $cells[$e['employee_id']][substr((string) $e['work_date'], 0, 10)] = ['pattern_id' => $e['pattern_id'], 'code' => $e['pattern_code'], 'off' => (bool) $e['is_off']];
        }

        $onLeave = [];

        foreach ($this->leave->daysBetween($property, $from, $to, null) as $l) {
            $onLeave[$l['employee_id']][substr((string) $l['work_date'], 0, 10)] = $l['type_code'];
        }

        $patterns = $this->roster->patterns($property, false);
        $minimums = $this->roster->minimums($property);

        return [
            'from' => $from, 'to' => $to, 'days' => $days, 'department' => $department, 'business_date' => $today,
            'employees' => array_map(static fn (array $e): array => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department'], 'position' => $e['position'], 'joined_on' => substr((string) $e['joined_on'], 0, 10), 'contract_end_on' => $e['contract_end_on'] === null ? null : substr((string) $e['contract_end_on'], 0, 10)], $people),
            'cells' => $cells === [] ? new \stdClass : $cells, 'leave' => $onLeave === [] ? new \stdClass : $onLeave, 'patterns' => array_map(fn (array $p): array => $this->shape($p), $patterns),
            'minimums' => array_map(static fn (array $m): array => ['department' => $m['department'], 'pattern_id' => $m['pattern_id'], 'minimum' => (int) $m['minimum']], $minimums),
            'shortages' => $this->shortages($property, $from, $to, $today, $department, $patterns, $minimums),
            'departments' => EmployeeService::DEPARTMENTS, 'may' => $caps,
        ];
    }

    /**
     * @param  list<string>  $employeeIds
     * @param  list<string>  $dates
     * @return array{assigned: int, cleared: int}
     */
    public function assign(PropertyId $property, string $actorId, array $employeeIds, array $dates, ?string $patternId): array
    {
        $this->access->require($property, $actorId, HrAccess::ROSTER, 'This person may not plan the roster.');
        $employeeIds = array_values(array_unique(array_map('strtolower', $employeeIds)));
        $dates = array_values(array_unique($dates));
        sort($dates);

        if ($employeeIds === [] || $dates === [] || count($dates) > self::MAX_DAYS || count($employeeIds) * count($dates) > self::MAX_CELLS) {
            throw Refusal::invalid('Choose people and days, at most '.self::MAX_CELLS.' days in all.', ['employee_ids', 'dates']);
        }

        $today = $this->businessDate->current($property)->toString();

        foreach ($dates as $d) {
            if (! $this->isDate($d)) {
                throw Refusal::invalid('Give each day as year-month-day.', ['dates']);
            }

            if ($d < $today) {
                throw Refusal::invalid('A day that is past is not planned again.', ['dates']);
            }
        }

        $pattern = null;

        if ($patternId !== null && $patternId !== '') {
            $pattern = $this->roster->pattern($property, strtolower($patternId));

            if ($pattern === null || ! (bool) $pattern['is_active']) {
                throw Refusal::invalid('Choose a shift that is in use.', ['pattern_id']);
            }
        }

        $actor = strtolower($actorId);
        $people = [];

        foreach ($employeeIds as $id) {
            $e = $this->employees->employee($property, $id) ?? throw Refusal::invalid('Choose employees of this property.', ['employee_ids']);

            if ($e['status'] !== 'active') {
                throw Refusal::invalid("{$e['number']} has left and is not planned.", ['employee_ids']);
            }

            $people[$id] = $e;
        }

        if ($pattern !== null) {
            foreach ($people as $e) {
                foreach ($dates as $d) {
                    if ($d < substr((string) $e['joined_on'], 0, 10) || ($e['contract_end_on'] !== null && $d > substr((string) $e['contract_end_on'], 0, 10))) {
                        throw Refusal::invalid("{$e['number']} is not employed on {$d}.", ['dates']);
                    }
                }
            }
        }

        if ($pattern !== null) {
            foreach ($this->leave->daysBetween($property, $dates[0], $dates[count($dates) - 1], array_keys($people)) as $l) {
                if (in_array(substr((string) $l['work_date'], 0, 10), $dates, true)) {
                    throw Refusal::invalid("{$people[$l['employee_id']]['number']} is on leave on ".substr((string) $l['work_date'], 0, 10).'.', ['dates']);
                }
            }
        }

        $assigned = 0;
        $cleared = 0;

        $this->transactions->run(function () use ($property, $actor, $people, $dates, $pattern, &$assigned, &$cleared): void {
            $now = $this->clock->nowUtc();

            foreach ($people as $e) {
                foreach ($dates as $d) {
                    if ($pattern === null) {
                        $had = $this->roster->entry($property, $e['id'], $d) !== null;
                        $this->roster->removeEntry($property, $e['id'], $d);
                        $cleared += $had ? 1 : 0;

                        continue;
                    }

                    $this->roster->putEntry($property, $this->row($e, $d, $pattern, $actor), $now);
                    $assigned++;
                }
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $pattern === null ? 'roster.cleared' : 'roster.assigned', 'roster', $property->toString(), null, [
                'shift' => $pattern['code'] ?? null, 'people' => count($people), 'days' => count($dates), 'from' => $dates[0], 'to' => $dates[count($dates) - 1], 'changed' => $assigned + $cleared,
            ]));
        });

        return ['assigned' => $assigned, 'cleared' => $cleared];
    }

    /**
     * Plans the days of a week like the week before: only empty days, only the people still employed, only days that are not past.
     *
     * @return array{copied: int, skipped: int}
     */
    public function copyWeek(PropertyId $property, string $actorId, string $fromStart, string $toStart, ?string $department): array
    {
        $this->access->require($property, $actorId, HrAccess::ROSTER, 'This person may not plan the roster.');

        if (! $this->isDate($fromStart) || ! $this->isDate($toStart) || $fromStart === $toStart) {
            throw Refusal::invalid('Choose the week to copy and the week to fill.', ['from_start', 'to_start']);
        }

        $department = $department === '' ? null : $department;
        $shift = DateMath::daysBetween($fromStart, $toStart);
        $today = $this->businessDate->current($property)->toString();
        $copied = 0;
        $skipped = 0;
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $fromStart, $shift, $today, $department, &$copied, &$skipped): void {
            $now = $this->clock->nowUtc();
            $source = $this->roster->entriesBetween($property, $fromStart, DateMath::format('Y-m-d', $fromStart.' +6 days'), $department);
            $leaves = [];

            foreach ($this->leave->daysBetween($property, DateMath::format('Y-m-d', $fromStart.' '.($shift >= 0 ? '+' : '').$shift.' days'), DateMath::format('Y-m-d', $fromStart.' +6 days '.($shift >= 0 ? '+' : '').$shift.' days'), null) as $l) {
                $leaves[$l['employee_id'].'|'.substr((string) $l['work_date'], 0, 10)] = true;
            }

            foreach ($source as $s) {
                $date = DateMath::format('Y-m-d', substr((string) $s['work_date'], 0, 10).' '.($shift >= 0 ? '+' : '').$shift.' days');
                $e = $this->employees->employee($property, $s['employee_id']);
                $pattern = $this->roster->pattern($property, $s['pattern_id']);

                if ($e === null || $pattern === null || $e['status'] !== 'active' || ! (bool) $pattern['is_active'] || $date < $today || $date < substr((string) $e['joined_on'], 0, 10)
                    || ($e['contract_end_on'] !== null && $date > substr((string) $e['contract_end_on'], 0, 10)) || isset($leaves[$e['id'].'|'.$date]) || $this->roster->entry($property, $e['id'], $date) !== null) {
                    $skipped++;

                    continue;
                }

                $this->roster->putEntry($property, $this->row($e, $date, $pattern, $actor), $now);
                $copied++;
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'roster.copied', 'roster', $property->toString(), null, ['from_week' => $fromStart, 'shifted_days' => $shift, 'department' => $department, 'copied' => $copied, 'skipped' => $skipped]));
        });

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** Takes the days of a person who leaves, from the day after they left, out of the roster. @return int how many days */
    public function closeFor(PropertyId $property, string $employeeId, string $leftOn): int
    {
        return $this->roster->removeEntriesFrom($property, $employeeId, DateMath::format('Y-m-d', $leftOn.' +1 day'));
    }

    /** @return list<array<string, mixed>> */
    public function patterns(PropertyId $property, string $actorId): array
    {
        $this->capabilities($property, $actorId);

        return array_map(fn (array $p): array => $this->shape($p), $this->roster->patterns($property, false));
    }

    /** @return array<string, mixed> */
    public function createPattern(PropertyId $property, string $actorId, string $code, string $name, bool $off, ?string $starts, ?string $ends, ?string $starts2, ?string $ends2): array
    {
        $this->access->require($property, $actorId, HrAccess::ROSTER, 'This person may not configure the shifts.');
        $clean = $this->cleanPattern($code, $name, $off, $starts, $ends, $starts2, $ends2);
        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $clean): void {
            if (! $this->roster->addPattern($property, ['id' => $id, ...$clean], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A shift with this code exists already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'shift_pattern.created', 'shift_pattern', $id, null, $clean));
        });

        return $this->shape($this->roster->pattern($property, $id) ?? throw Refusal::notFound('Shift not found.'));
    }

    /** @return array<string, mixed> */
    public function updatePattern(PropertyId $property, string $actorId, string $id, string $name, ?string $starts, ?string $ends, ?string $starts2, ?string $ends2, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::ROSTER, 'This person may not configure the shifts.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $starts, $ends, $starts2, $ends2, $lock): void {
            $before = $this->roster->pattern($property, strtolower($id)) ?? throw Refusal::notFound('Shift not found.');
            $clean = $this->cleanPattern($before['code'], $name, (bool) $before['is_off'], $starts, $ends, $starts2, $ends2);
            unset($clean['code'], $clean['is_off']);

            if (! $this->roster->updatePattern($property, $before['id'], $lock, $clean, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This shift changed after you opened it. Reload it.');
            }

            // Days already planned keep the times they were planned with.
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'shift_pattern.changed', 'shift_pattern', $before['id'], ['name' => $before['name'], 'starts_at' => $before['starts_at'], 'ends_at' => $before['ends_at'], 'starts2_at' => $before['starts2_at'], 'ends2_at' => $before['ends2_at']], $clean));
        });

        return $this->shape($this->roster->pattern($property, strtolower($id)) ?? throw Refusal::notFound('Shift not found.'));
    }

    /** @return array<string, mixed> */
    public function setPatternActive(PropertyId $property, string $actorId, string $id, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::ROSTER, 'This person may not configure the shifts.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $active, $lock): void {
            $before = $this->roster->pattern($property, strtolower($id)) ?? throw Refusal::notFound('Shift not found.');

            if ((bool) $before['is_active'] === $active) {
                throw Refusal::stateConflict($active ? 'This shift is in use already.' : 'This shift is retired already.');
            }

            if (! $this->roster->updatePattern($property, $before['id'], $lock, ['is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This shift changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'shift_pattern.resumed' : 'shift_pattern.retired', 'shift_pattern', $before['id'], ['is_active' => (bool) $before['is_active']], ['is_active' => $active, 'code' => $before['code']]));
        });

        return $this->shape($this->roster->pattern($property, strtolower($id)) ?? throw Refusal::notFound('Shift not found.'));
    }

    /** Writes the usual shifts of an Indonesian hotel (morning, afternoon, night, split, day off) when none was written yet. @return list<array<string, mixed>> */
    public function baseline(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::ROSTER, 'This person may not configure the shifts.');

        if ($this->roster->patterns($property, false) !== []) {
            throw Refusal::stateConflict('Shifts were written already.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor): void {
            $now = $this->clock->nowUtc();

            foreach (self::BASELINE as [$code, $name, $off, $s, $e, $s2, $e2]) {
                $this->roster->addPattern($property, ['id' => $this->ids->next(), ...$this->cleanPattern($code, $name, $off, $s, $e, $s2, $e2)], $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'shift_pattern.baseline', 'shift_pattern', $property->toString(), null, ['codes' => array_column(self::BASELINE, 0)]));
        });

        return $this->patterns($property, $actorId);
    }

    /**
     * @param  array<string, int>  $minimums  shift id to the fewest people
     * @return array<string, mixed>
     */
    public function saveMinimums(PropertyId $property, string $actorId, string $department, array $minimums): array
    {
        $this->access->require($property, $actorId, HrAccess::ROSTER, 'This person may not set the staffing needs.');

        if (! in_array($department, EmployeeService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department of the list.', ['department']);
        }

        $actor = strtolower($actorId);

        foreach ($minimums as $patternId => $min) {
            $p = $this->roster->pattern($property, strtolower((string) $patternId));

            if ($p === null || (bool) $p['is_off']) {
                throw Refusal::invalid('Choose shifts that are worked, not days off.', ['minimums']);
            }

            if ((int) $min < 0 || (int) $min > 200) {
                throw Refusal::invalid('The fewest people is from 0 to 200.', ['minimums']);
            }
        }

        $this->transactions->run(function () use ($property, $actor, $department, $minimums): void {
            $now = $this->clock->nowUtc();
            $before = [];

            foreach ($this->roster->minimums($property) as $m) {
                if ($m['department'] === $department) {
                    $before[$m['pattern_id']] = (int) $m['minimum'];
                }
            }

            foreach ($minimums as $patternId => $min) {
                $this->roster->setMinimum($property, $department, strtolower((string) $patternId), (int) $min, $actor, $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'staffing_minimum.changed', 'staffing_minimum', $property->toString(), $before, ['department' => $department, 'minimums' => array_map('intval', $minimums)]));
        });

        return ['minimums' => array_map(static fn (array $m): array => ['department' => $m['department'], 'pattern_id' => $m['pattern_id'], 'minimum' => (int) $m['minimum']], $this->roster->minimums($property))];
    }

    /**
     * @param  list<array<string, mixed>>  $patterns
     * @param  list<array<string, mixed>>  $minimums
     * @return list<array{date: string, department: string, pattern_id: string, code: string, have: int, need: int}>
     */
    private function shortages(PropertyId $property, string $from, string $to, string $today, ?string $department, array $patterns, array $minimums): array
    {
        $start = max($from, $today);
        $out = [];

        if ($start > $to) {
            return [];
        }

        $count = [];

        foreach ($this->roster->entriesBetween($property, $start, $to, null) as $e) {
            if (! (bool) $e['is_off'] && $e['employee_status'] === 'active') {
                $key = substr((string) $e['work_date'], 0, 10).'|'.$e['department'].'|'.$e['pattern_id'];
                $count[$key] = ($count[$key] ?? 0) + 1;
            }
        }

        $codes = array_column($patterns, 'code', 'id');

        foreach ($minimums as $m) {
            if (($department !== null && $m['department'] !== $department) || ! isset($codes[$m['pattern_id']])) {
                continue;
            }

            for ($d = $start; $d <= $to; $d = DateMath::format('Y-m-d', $d.' +1 day')) {
                $have = $count[$d.'|'.$m['department'].'|'.$m['pattern_id']] ?? 0;

                if ($have < (int) $m['minimum']) {
                    $out[] = ['date' => $d, 'department' => $m['department'], 'pattern_id' => $m['pattern_id'], 'code' => $codes[$m['pattern_id']], 'have' => $have, 'need' => (int) $m['minimum']];
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['date'], $a['department'], $a['code']] <=> [$b['date'], $b['department'], $b['code']]);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function row(array $e, string $date, array $p, string $actor): array
    {
        return [
            'id' => $this->ids->next(), 'employee_id' => $e['id'], 'work_date' => $date, 'pattern_id' => $p['id'], 'pattern_code' => $p['code'], 'department' => $e['department'], 'is_off' => (bool) $p['is_off'],
            'starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'], 'starts2_at' => $p['starts2_at'], 'ends2_at' => $p['ends2_at'], 'minutes' => $this->minutes($p), 'planned_by' => $actor,
        ];
    }

    /** @param array<string, mixed> $p */
    private function minutes(array $p): int
    {
        if ((bool) $p['is_off'] || $p['starts_at'] === null) {
            return 0;
        }

        $span = static function (string $a, string $b): int {
            $m = ((int) substr($b, 0, 2) * 60 + (int) substr($b, 3, 2)) - ((int) substr($a, 0, 2) * 60 + (int) substr($a, 3, 2));

            return $m <= 0 ? $m + 1440 : $m;
        };

        return $span($p['starts_at'], $p['ends_at']) + ($p['starts2_at'] === null ? 0 : $span($p['starts2_at'], $p['ends2_at']));
    }

    /** @return array{code: string, name: string, is_off: bool, starts_at: string|null, ends_at: string|null, starts2_at: string|null, ends2_at: string|null} */
    private function cleanPattern(string $code, string $name, bool $off, ?string $starts, ?string $ends, ?string $starts2, ?string $ends2): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);
        $time = static fn (?string $t): ?string => $t === null || $t === '' ? null : $t;
        [$starts, $ends, $starts2, $ends2] = [$time($starts), $time($ends), $time($starts2), $time($ends2)];

        if (preg_match('/^[A-Z0-9]{1,8}$/', $code) !== 1) {
            throw Refusal::invalid('The code is one to eight letters or digits.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 40) {
            throw Refusal::invalid('Name the shift in at most 40 characters.', ['name']);
        }

        if ($off) {
            if ($starts !== null || $ends !== null || $starts2 !== null || $ends2 !== null) {
                throw Refusal::invalid('A day off has no times.', ['starts_at']);
            }

            return ['code' => $code, 'name' => $name, 'is_off' => true, 'starts_at' => null, 'ends_at' => null, 'starts2_at' => null, 'ends2_at' => null];
        }

        foreach ([[$starts, 'starts_at'], [$ends, 'ends_at'], [$starts2, 'starts2_at'], [$ends2, 'ends2_at']] as [$t, $field]) {
            if ($t !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) !== 1) {
                throw Refusal::invalid('Give the time as hours and minutes, like 07:00.', [$field]);
            }
        }

        if ($starts === null || $ends === null || $starts === $ends) {
            throw Refusal::invalid('Give the time the shift starts and the time it ends.', ['starts_at', 'ends_at']);
        }

        if (($starts2 === null) !== ($ends2 === null)) {
            throw Refusal::invalid('A split shift has a start and an end of its second part.', ['starts2_at', 'ends2_at']);
        }

        if ($starts2 !== null && ! ($ends > $starts && $starts2 > $ends && $ends2 > $starts2)) {
            throw Refusal::invalid('The two parts of a split shift are in one day, one after the other.', ['starts2_at', 'ends2_at']);
        }

        return ['code' => $code, 'name' => $name, 'is_off' => false, 'starts_at' => $starts, 'ends_at' => $ends, 'starts2_at' => $starts2, 'ends2_at' => $ends2];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function shape(array $p): array
    {
        return [
            'id' => $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'off' => (bool) $p['is_off'], 'starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'], 'starts2_at' => $p['starts2_at'], 'ends2_at' => $p['ends2_at'],
            'minutes' => $this->minutes($p), 'active' => (bool) $p['is_active'], 'lock_version' => (int) $p['lock_version'],
        ];
    }

    /** @return array{view: bool, roster: bool} */
    private function capabilities(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $roster = $this->access->may($property, $actorId, HrAccess::ROSTER);
        $view = $roster || $this->access->may($property, $actorId, HrAccess::MANAGE) || $this->access->may($property, $actorId, HrAccess::VIEW);

        if (! $view) {
            throw Refusal::forbidden('This person may not see the roster.');
        }

        return ['view' => $view, 'roster' => $roster];
    }

    private function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
