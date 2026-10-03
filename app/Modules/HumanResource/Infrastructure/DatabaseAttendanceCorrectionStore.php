<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\AttendanceCorrectionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseAttendanceCorrectionStore implements AttendanceCorrectionStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_attendance_corrections')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = $this->joined()->where('c.property_id', $property->toString())->where('c.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_attendance_corrections')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function pendingFor(PropertyId $property, string $employeeId, string $date): ?array
    {
        $r = $this->joined()->where('c.property_id', $property->toString())->where('c.employee_id', $employeeId)->where('c.work_date', $date)->where('c.status', 'pending_approval')->first();

        return $r === null ? null : (array) $r;
    }

    public function latest(PropertyId $property, int $limit): array
    {
        return $this->joined()->where('c.property_id', $property->toString())->orderByDesc('c.created_at')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    private function joined(): Builder
    {
        return DB::table('hr_attendance_corrections as c')->join('hr_employees as e', 'e.id', '=', 'c.employee_id')->select('c.*', 'e.number', 'e.full_name', 'e.department');
    }
}
