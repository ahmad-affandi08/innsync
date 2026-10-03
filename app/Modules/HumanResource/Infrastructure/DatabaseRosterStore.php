<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\RosterStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseRosterStore implements RosterStore
{
    public function addPattern(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('hr_shift_patterns')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function patterns(PropertyId $property, bool $onlyActive): array
    {
        return DB::table('hr_shift_patterns')->where('property_id', $property->toString())->when($onlyActive, static fn ($q) => $q->where('is_active', true))->orderBy('is_off')->orderBy('starts_at')->orderBy('code')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function pattern(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_shift_patterns')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function updatePattern(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_shift_patterns')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function entriesBetween(PropertyId $property, string $from, string $to, ?string $department): array
    {
        return DB::table('hr_roster_entries as r')->join('hr_employees as e', 'e.id', '=', 'r.employee_id')->where('r.property_id', $property->toString())->whereBetween('r.work_date', [$from, $to])
            ->when($department !== null, static fn ($q) => $q->where('r.department', $department))->orderBy('r.work_date')->orderBy('e.full_name')->select('r.*', 'e.number', 'e.full_name', 'e.status as employee_status')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function entry(PropertyId $property, string $employeeId, string $date): ?array
    {
        $r = DB::table('hr_roster_entries')->where('property_id', $property->toString())->where('employee_id', $employeeId)->where('work_date', $date)->first();

        return $r === null ? null : (array) $r;
    }

    public function putEntry(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $key = ['property_id' => $property->toString(), 'employee_id' => $row['employee_id'], 'work_date' => $row['work_date']];

        if (DB::table('hr_roster_entries')->where($key)->exists()) {
            DB::table('hr_roster_entries')->where($key)->update([...array_diff_key($row, ['id' => 1, 'employee_id' => 1, 'work_date' => 1]), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);

            return;
        }

        DB::table('hr_roster_entries')->insert([...$row, 'id' => $row['id'] ?? strtolower((string) Str::ulid()), 'property_id' => $property->toString(), 'created_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function removeEntry(PropertyId $property, string $employeeId, string $date): void
    {
        DB::table('hr_roster_entries')->where('property_id', $property->toString())->where('employee_id', $employeeId)->where('work_date', $date)->delete();
    }

    public function removeEntriesFrom(PropertyId $property, string $employeeId, string $from): int
    {
        return DB::table('hr_roster_entries')->where('property_id', $property->toString())->where('employee_id', $employeeId)->where('work_date', '>=', $from)->delete();
    }

    public function minimums(PropertyId $property): array
    {
        return DB::table('hr_staffing_minimums')->where('property_id', $property->toString())->orderBy('department')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function setMinimum(PropertyId $property, string $department, string $patternId, int $minimum, string $by, DateTimeImmutable $at): void
    {
        $key = ['property_id' => $property->toString(), 'department' => $department, 'pattern_id' => $patternId];

        if ($minimum === 0) {
            DB::table('hr_staffing_minimums')->where($key)->delete();

            return;
        }

        $stamp = $at->format('Y-m-d H:i:s.u');

        if (DB::table('hr_staffing_minimums')->where($key)->exists()) {
            DB::table('hr_staffing_minimums')->where($key)->update(['minimum' => $minimum, 'updated_by' => $by, 'updated_at' => $stamp]);

            return;
        }

        DB::table('hr_staffing_minimums')->insert([...$key, 'id' => strtolower((string) Str::ulid()), 'minimum' => $minimum, 'updated_by' => $by, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
}
