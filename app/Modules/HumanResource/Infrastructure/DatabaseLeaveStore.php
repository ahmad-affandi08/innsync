<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\LeaveStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseLeaveStore implements LeaveStore
{
    private const OPEN = ['pending_approval', 'approved'];

    public function addType(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_leave_types')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function types(PropertyId $property, bool $onlyActive): array
    {
        return DB::table('hr_leave_types')->where('property_id', $property->toString())->when($onlyActive, static fn ($q) => $q->where('is_active', true))->orderByDesc('deducts_balance')->orderBy('code')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function type(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_leave_types')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function updateType(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_leave_types')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function addRequest(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_leave_requests')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function request(PropertyId $property, string $id): ?array
    {
        $r = $this->joined()->where('l.property_id', $property->toString())->where('l.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function updateRequest(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_leave_requests')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function openBetween(PropertyId $property, string $employeeId, string $from, string $to): array
    {
        return $this->joined()->where('l.property_id', $property->toString())->where('l.employee_id', $employeeId)->whereIn('l.status', self::OPEN)->where('l.from_date', '<=', $to)->where('l.to_date', '>=', $from)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function requests(PropertyId $property, ?string $employeeId, ?string $status, int $limit): array
    {
        return $this->joined()->where('l.property_id', $property->toString())->when($employeeId !== null, static fn ($q) => $q->where('l.employee_id', $employeeId))->when($status !== null, static fn ($q) => $q->where('l.status', $status))
            ->orderByDesc('l.from_date')->orderByDesc('l.created_at')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function openEndingAfter(PropertyId $property, string $employeeId, string $day): array
    {
        return $this->joined()->where('l.property_id', $property->toString())->where('l.employee_id', $employeeId)->whereIn('l.status', self::OPEN)->where('l.to_date', '>', $day)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function evidenceFiles(PropertyId $property, string $employeeId): array
    {
        return DB::table('hr_leave_requests')->where('property_id', $property->toString())->where('employee_id', $employeeId)->whereNotNull('evidence_file_id')->pluck('evidence_file_id')->map(static fn ($v): string => (string) $v)->all();
    }

    public function addDay(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_leave_days')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function daysBetween(PropertyId $property, string $from, string $to, ?array $employeeIds): array
    {
        return DB::table('hr_leave_days')->where('property_id', $property->toString())->whereBetween('work_date', [$from, $to])->when($employeeIds !== null, static fn ($q) => $q->whereIn('employee_id', $employeeIds))->orderBy('work_date')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function daysOf(PropertyId $property, string $leaveId): array
    {
        return DB::table('hr_leave_days')->where('property_id', $property->toString())->where('leave_id', $leaveId)->orderBy('work_date')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function removeDays(PropertyId $property, string $leaveId, string $from): int
    {
        return DB::table('hr_leave_days')->where('property_id', $property->toString())->where('leave_id', $leaveId)->where('work_date', '>=', $from)->delete();
    }

    public function addAdjustment(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_leave_adjustments')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function adjustments(PropertyId $property, int $year, ?string $employeeId): array
    {
        return DB::table('hr_leave_adjustments as a')->join('hr_employees as e', 'e.id', '=', 'a.employee_id')->join('hr_leave_types as t', 't.id', '=', 'a.leave_type_id')->where('a.property_id', $property->toString())->where('a.year', $year)
            ->when($employeeId !== null, static fn ($q) => $q->where('a.employee_id', $employeeId))->orderByDesc('a.created_at')->select('a.*', 'e.number', 'e.full_name', 't.code as type_code')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function daysTaken(PropertyId $property, int $year, ?string $employeeId): array
    {
        return DB::table('hr_leave_requests')->where('property_id', $property->toString())->where('deducts_balance', true)->whereIn('status', self::OPEN)->whereBetween('from_date', ["{$year}-01-01", "{$year}-12-31"])
            ->when($employeeId !== null, static fn ($q) => $q->where('employee_id', $employeeId))->groupBy('employee_id', 'leave_type_id', 'status')->selectRaw('employee_id, leave_type_id, status, sum(days) as days')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    private function joined(): Builder
    {
        return DB::table('hr_leave_requests as l')->join('hr_employees as e', 'e.id', '=', 'l.employee_id')->select('l.*', 'e.number', 'e.full_name', 'e.department');
    }

    public function unpaidDaysBetween(PropertyId $property, string $from, string $to): array
    {
        $out = [];

        foreach (DB::table('hr_leave_days as d')->join('hr_leave_requests as r', 'r.id', '=', 'd.leave_id')->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('d.property_id', $property->toString())->whereBetween('d.work_date', [$from, $to])->where('t.paid', false)->groupBy('d.employee_id')->selectRaw('d.employee_id, COUNT(*) as days')->get() as $row) {
            $out[(string) $row->employee_id] = (int) $row->days;
        }

        return $out;
    }
}
