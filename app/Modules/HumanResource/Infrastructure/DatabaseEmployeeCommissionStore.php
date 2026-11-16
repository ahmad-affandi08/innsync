<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\EmployeeCommissionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseEmployeeCommissionStore implements EmployeeCommissionStore
{
    public function addType(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_commission_types')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function types(PropertyId $property): array
    {
        return DB::table('hr_commission_types')->where('property_id', $property->toString())->orderBy('name')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function type(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_commission_types')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function typeByCode(PropertyId $property, string $code): ?array
    {
        $r = DB::table('hr_commission_types')->where('property_id', $property->toString())->where('code', $code)->first();

        return $r === null ? null : (array) $r;
    }

    public function updateType(PropertyId $property, string $id, int $lock, string $name, bool $active, DateTimeImmutable $at): bool
    {
        return DB::table('hr_commission_types')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)
            ->update(['name' => $name, 'is_active' => $active, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('hr_commission_entries')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at->format('Y-m-d H:i:s.u')]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function entry(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_commission_entries')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function entryView(PropertyId $property, string $id): ?array
    {
        $r = $this->joined($property)->where('c.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function entries(PropertyId $property, ?string $employeeId, int $limit): array
    {
        return $this->joined($property)->when($employeeId !== null, static fn ($q) => $q->where('c.employee_id', $employeeId))->orderByDesc('c.earned_on')->orderByDesc('c.created_at')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    private function joined(PropertyId $property): Builder
    {
        return DB::table('hr_commission_entries as c')->join('hr_employees as e', 'e.id', '=', 'c.employee_id')->join('hr_commission_types as t', 't.id', '=', 'c.type_id')
            ->where('c.property_id', $property->toString())->select('c.*', 'e.number', 'e.full_name', 't.code as type_code', 't.name as type_name');
    }

    public function totalsOf(PropertyId $property, string $employeeId): array
    {
        return DB::table('hr_commission_entries as c')->join('hr_commission_types as t', 't.id', '=', 'c.type_id')
            ->where('c.property_id', $property->toString())->where('c.employee_id', $employeeId)->groupBy('t.id', 't.code', 't.name')->orderBy('t.name')
            ->get(['t.id as type_id', 't.code', 't.name', DB::raw('SUM(c.amount_minor) as total_minor')])
            ->map(static fn (object $r): array => ['type_id' => (string) $r->type_id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'total_minor' => (int) $r->total_minor])->all();
    }
}
