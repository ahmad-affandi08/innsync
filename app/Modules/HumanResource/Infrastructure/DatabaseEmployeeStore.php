<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\EmployeeStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseEmployeeStore implements EmployeeStore
{
    public function addEmployee(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('hr_employees')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'active', 'lock_version' => 0, 'created_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function employee(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_employees as e')->leftJoin('hr_employees as s', 's.id', '=', 'e.supervisor_id')->where('e.property_id', $property->toString())->where('e.id', $id)->select('e.*', 's.full_name as supervisor_name', 's.number as supervisor_number')->first();

        return $r === null ? null : (array) $r;
    }

    public function employees(PropertyId $property, ?string $status): array
    {
        return DB::table('hr_employees as e')->leftJoin('hr_employees as s', 's.id', '=', 'e.supervisor_id')->where('e.property_id', $property->toString())->when($status !== null, static fn ($q) => $q->where('e.status', $status))
            ->orderBy('e.full_name')->orderBy('e.number')->select('e.*', 's.full_name as supervisor_name', 's.number as supervisor_number')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function lockEmployee(PropertyId $property, string $id): void
    {
        DB::table('hr_employees')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->value('id');
    }

    public function updateEmployee(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_employees')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function subordinatesOf(PropertyId $property, string $id): array
    {
        return DB::table('hr_employees')->where('property_id', $property->toString())->where('supervisor_id', $id)->where('status', 'active')->pluck('id')->map(static fn ($v): string => (string) $v)->all();
    }

    public function reassign(PropertyId $property, string $from, string $to, DateTimeImmutable $at): int
    {
        return DB::table('hr_employees')->where('property_id', $property->toString())->where('supervisor_id', $from)->where('status', 'active')->update(['supervisor_id' => $to, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function employeeOfUser(PropertyId $property, string $userId): ?string
    {
        $id = DB::table('hr_employees')->where('property_id', $property->toString())->where('user_id', $userId)->value('id');

        return $id === null ? null : (string) $id;
    }

    public function addDocument(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('hr_documents')->insert([...$row, 'property_id' => $property->toString(), 'is_current' => true, 'created_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function documents(PropertyId $property, string $employeeId): array
    {
        return DB::table('hr_documents')->where('property_id', $property->toString())->where('employee_id', $employeeId)->orderByDesc('created_at')->orderByDesc('id')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function document(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_documents')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function retireDocument(PropertyId $property, string $id, DateTimeImmutable $at): void
    {
        DB::table('hr_documents')->where('property_id', $property->toString())->where('id', $id)->update(['is_current' => false, 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function expiringDocuments(PropertyId $property, string $until): array
    {
        return DB::table('hr_documents as d')->join('hr_employees as e', 'e.id', '=', 'd.employee_id')->where('d.property_id', $property->toString())->where('e.status', 'active')->where('d.is_current', true)
            ->whereNotNull('d.valid_until')->where('d.valid_until', '<=', $until)->orderBy('d.valid_until')->select('d.id', 'd.kind', 'd.title', 'd.valid_until', 'e.id as employee_id', 'e.number', 'e.full_name', 'e.department')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function currentKinds(PropertyId $property): array
    {
        $out = [];

        foreach (DB::table('hr_documents as d')->join('hr_employees as e', 'e.id', '=', 'd.employee_id')->where('d.property_id', $property->toString())->where('e.status', 'active')->where('d.is_current', true)->select('d.employee_id', 'd.kind')->distinct()->get() as $r) {
            $out[(string) $r->employee_id][] = (string) $r->kind;
        }

        return $out;
    }

    public function addOffboardItem(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('hr_offboard_items')->insert([...$row, 'created_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function offboardItems(PropertyId $property, string $employeeId): array
    {
        return DB::table('hr_offboard_items as i')->join('hr_employees as e', 'e.id', '=', 'i.employee_id')->where('e.property_id', $property->toString())->where('i.employee_id', $employeeId)->orderBy('i.created_at')->orderBy('i.id')->select('i.*')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function fileIdsOf(PropertyId $property, string $employeeId): array
    {
        return DB::table('hr_documents')->where('property_id', $property->toString())->where('employee_id', $employeeId)->pluck('file_id')->map(static fn ($v): string => (string) $v)->all();
    }

    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('hr_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : (array) $r;
    }

    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $row = ['expiry_warn_days' => $values['warn_days'], 'required_kinds' => implode(',', $values['required_kinds'])];

        if ($expectedLock === null) {
            try {
                DB::table('hr_settings')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'updated_by' => $by, 'created_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('hr_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLock)->update([...$row, 'lock_version' => $expectedLock + 1, 'updated_by' => $by, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }
}
