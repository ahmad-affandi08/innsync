<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\OvertimeStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseOvertimeStore implements OvertimeStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_overtime')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = $this->joined()->where('o.property_id', $property->toString())->where('o.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_overtime')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function openFor(PropertyId $property, string $employeeId, string $date): ?array
    {
        $r = $this->joined()->where('o.property_id', $property->toString())->where('o.employee_id', $employeeId)->where('o.work_date', $date)->whereIn('o.status', ['pending_approval', 'approved'])->first();

        return $r === null ? null : (array) $r;
    }

    public function between(PropertyId $property, string $from, string $to): array
    {
        return $this->joined()->where('o.property_id', $property->toString())->whereBetween('o.work_date', [$from, $to])->orderByDesc('o.work_date')->orderBy('e.full_name')->orderByDesc('o.created_at')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function approvedBetween(PropertyId $property, string $from, string $to, ?string $employeeId): array
    {
        return DB::table('hr_overtime')->where('property_id', $property->toString())->where('status', 'approved')->whereBetween('work_date', [$from, $to])
            ->when($employeeId !== null, static fn ($q) => $q->where('employee_id', $employeeId))->get(['employee_id', 'work_date', 'minutes', 'approved_at'])->map(static fn ($r): array => (array) $r)->all();
    }

    private function joined(): Builder
    {
        return DB::table('hr_overtime as o')->join('hr_employees as e', 'e.id', '=', 'o.employee_id')->select('o.*', 'e.number', 'e.full_name', 'e.department');
    }
}
