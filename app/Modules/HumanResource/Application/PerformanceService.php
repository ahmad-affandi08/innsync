<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\FrontOffice\Application\Feedback\ComplaintWorkload;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\CalendarDate;

/**
 * How people are doing (FR-HR-020, FR-HR-021): for a period, each person's attendance and punctuality, the share of their routines they did (what the operational modules tell Human Resource when
 * an item is ticked), the guest complaints they own, and the overtime; and for each source the share of its checklists that was done. It counts what happened; it does not judge.
 */
final readonly class PerformanceService
{
    public const MAX_DAYS = 93;

    public function __construct(
        private PerformanceStore $store,
        private AttendanceService $attendance,
        private EmployeeStore $employees,
        private ComplaintWorkload $complaints,
        private HrAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyTimeZoneReader $zones,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $department): array
    {
        $this->access->require($property, $actorId, HrAccess::PERFORMANCE, 'This person may not see how people are doing.');
        $today = $this->businessDate->current($property)->toString();
        $from = $from === null || $from === '' ? date('Y-m-d', strtotime($today.' -29 days')) : $from;
        $to = $to === null || $to === '' ? $today : $to;
        $department = $department === '' ? null : $department;

        if (! ShiftTimes::isDate($from) || ! ShiftTimes::isDate($to) || $to < $from || (strtotime($to) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
            throw Refusal::invalid('Choose a period of at most '.self::MAX_DAYS.' days that ends after it starts.', ['from', 'to']);
        }

        if ($department !== null && ! in_array($department, EmployeeService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department of the list.', ['department']);
        }

        return $this->build($property, $from, $to, $department);
    }

    /** The objective figures of one person for a period (the caller has decided that the person may be appraised or see it). @return array<string, mixed>|null */
    public function snapshot(PropertyId $property, string $employeeId, string $from, string $to): ?array
    {
        $this->access->assertProperty($property);
        $employee = $this->employees->employee($property, $employeeId);

        if ($employee === null) {
            return null;
        }

        foreach ($this->build($property, $from, $to, $employee['department'])['board'] as $row) {
            if ($row['employee']['id'] === $employeeId) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function build(PropertyId $property, string $from, string $to, ?string $department): array
    {
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $attendance = [];

        foreach ($this->attendance->periodSummary($property, $from, $to, $department) as $row) {
            $attendance[$row['employee']['id']] = $row;
        }

        $credits = [];

        foreach ($this->store->creditsBetween($property, $from, $to) as $c) {
            $credits[$c['user_id']]['total'] = ($credits[$c['user_id']]['total'] ?? 0) + $c['credits'];
            $credits[$c['user_id']]['by'][$c['source']] = $c['credits'];
        }

        $people = array_values(array_filter($this->employees->employees($property, 'active'), static fn (array $e): bool => $department === null || $e['department'] === $department));
        $complaints = $this->complaints->ownedBy($property, array_values(array_filter(array_map(static fn (array $e): ?string => $e['user_id'] === null ? null : strtolower((string) $e['user_id']), $people))),
            $zone->utcAt(CalendarDate::fromString($from))->format('Y-m-d H:i:s'), $zone->utcAt(CalendarDate::fromString(date('Y-m-d', strtotime($to.' +1 day'))))->format('Y-m-d H:i:s'));

        $board = array_map(static function (array $e) use ($attendance, $credits, $complaints): array {
            $a = $attendance[$e['id']] ?? null;
            $user = $e['user_id'] === null ? null : strtolower((string) $e['user_id']);
            $present = $a['present'] ?? 0;
            $late = $a['late_days'] ?? 0;
            $scheduled = $a['scheduled'] ?? 0;

            return [
                'employee' => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department'], 'position' => $e['position']],
                'scheduled' => $scheduled, 'present' => $present, 'late_days' => $late, 'late_minutes' => $a['late_minutes'] ?? 0, 'absent' => $a['absent'] ?? 0,
                'punctuality' => $present === 0 ? null : intdiv(max(0, $present - $late) * 100, $present), 'attendance' => $scheduled === 0 ? null : intdiv(min($present, $scheduled) * 100, $scheduled),
                'overtime_minutes' => $a['overtime_minutes'] ?? 0, 'unapproved_minutes' => $a['unapproved_minutes'] ?? 0,
                'sop_items' => $user === null ? 0 : ($credits[$user]['total'] ?? 0), 'sop_by' => $user === null ? new \stdClass : ($credits[$user]['by'] ?? new \stdClass),
                'complaints' => $user === null ? ['total' => 0, 'serious' => 0, 'resolved' => 0] : ($complaints[$user] ?? ['total' => 0, 'serious' => 0, 'resolved' => 0]), 'linked' => $user !== null,
            ];
        }, $people);

        $sources = [];

        foreach ($this->store->runs($property, $from, $to) as $r) {
            $s = &$sources[$r['source']];
            $s ??= ['source' => $r['source'], 'runs' => 0, 'complete' => 0, 'items' => 0, 'done' => 0];
            $s['runs']++;
            $s['complete'] += (int) $r['percent'] === 100 ? 1 : 0;
            $s['items'] += (int) $r['total'];
            $s['done'] += (int) $r['completed'];
            unset($s);
        }

        return [
            'from' => $from, 'to' => $to, 'department' => $department, 'board' => $board, 'departments' => EmployeeService::DEPARTMENTS,
            'sources' => array_values(array_map(static fn (array $s): array => $s + ['percent' => $s['items'] === 0 ? 0 : intdiv($s['done'] * 100, $s['items'])], $sources)),
        ];
    }
}
