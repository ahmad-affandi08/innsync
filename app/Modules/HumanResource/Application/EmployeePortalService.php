<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The employee's own page (FR-HR-004): their schedule for the next two weeks, what they did over the last month, what is left of their leave, the payslips of the months that were paid, and the shift exchanges they
 * are in. Everything is read for the person the account belongs to and nobody else; the services it asks apply their own rules.
 */
final readonly class EmployeePortalService
{
    public const AHEAD_DAYS = 13;

    public const BACK_DAYS = 29;

    public function __construct(
        private EmployeeStore $employees,
        private RosterStore $roster,
        private AttendanceService $attendance,
        private LeaveService $leave,
        private PayslipService $payslips,
        private ShiftSwapService $swaps,
        private BusinessDateProvider $businessDate,
        private HrAccess $access,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();
        $id = $this->employees->employeeOfUser($property, $actor);
        $employee = $id === null ? null : $this->employees->employee($property, $id);

        if ($employee === null) {
            return ['linked' => false, 'today' => $today];
        }

        $to = date('Y-m-d', strtotime($today.' +'.self::AHEAD_DAYS.' days'));
        $schedule = [];

        foreach ($this->roster->entriesBetween($property, $today, $to, $employee['department']) as $e) {
            if ($e['employee_id'] === $employee['id']) {
                $schedule[] = ['date' => substr((string) $e['work_date'], 0, 10), 'code' => $e['pattern_code'], 'is_off' => (bool) $e['is_off'], 'starts_at' => $e['starts_at'], 'ends_at' => $e['ends_at'], 'starts2_at' => $e['starts2_at'], 'ends2_at' => $e['ends2_at']];
            }
        }

        $history = $this->attendance->historyOf($property, $employee['id'], $employee['department'], date('Y-m-d', strtotime($today.' -'.self::BACK_DAYS.' days')), $today);
        $leave = $this->leave->overview($property, $actorId, null, null);
        $swaps = $this->swaps->overview($property, $actorId);
        $slips = $this->payslips->mine($property, $actorId);

        return [
            'linked' => true, 'today' => $today, 'currency' => $slips['currency'],
            'employee' => ['id' => $employee['id'], 'number' => $employee['number'], 'name' => $employee['full_name'], 'department' => $employee['department'], 'position' => $employee['position'], 'joined_on' => substr((string) $employee['joined_on'], 0, 10)],
            'schedule' => $schedule,
            'attendance' => array_map(static fn (array $r): array => ['date' => $r['shift']['date'], 'code' => $r['shift']['code'], 'status' => $r['status'], 'in_at' => $r['record']['in_at'] ?? null, 'out_at' => $r['record']['out_at'] ?? null, 'late_minutes' => $r['late_minutes'], 'early_minutes' => $r['early_minutes'], 'overtime_minutes' => $r['overtime_minutes']], $history),
            'leave' => ['year' => $leave['year'], 'balances' => $leave['mine']['balances'] ?? [], 'pending' => count(array_filter($leave['mine']['requests'] ?? [], static fn (array $r): bool => $r['status'] === 'pending_approval'))],
            'payslips' => array_slice($slips['slips'], 0, 6),
            'swaps' => ['open' => count(array_filter($swaps['mine'], static fn (array $s): bool => in_array($s['status'], ['awaiting_partner', 'awaiting_supervisor'], true))), 'to_answer' => count(array_filter($swaps['mine'], static fn (array $s): bool => $s['may']['respond'])), 'to_decide' => count($swaps['to_decide'])],
        ];
    }
}
