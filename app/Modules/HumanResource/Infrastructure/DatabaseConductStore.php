<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\ConductStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseConductStore implements ConductStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_conduct_records')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = $this->joined()->where('c.property_id', $property->toString())->where('c.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_conduct_records')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function list(PropertyId $property, ?string $employeeId, int $limit): array
    {
        return $this->joined()->where('c.property_id', $property->toString())->when($employeeId !== null, static fn ($q) => $q->where('c.employee_id', $employeeId))->orderByDesc('c.issued_on')->orderByDesc('c.created_at')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function fileIdsOf(PropertyId $property, string $employeeId): array
    {
        return DB::table('hr_conduct_records')->where('property_id', $property->toString())->where('employee_id', $employeeId)->whereNotNull('file_id')->pluck('file_id')->map(static fn ($v): string => (string) $v)->all();
    }

    private function joined(): Builder
    {
        return DB::table('hr_conduct_records as c')->join('hr_employees as e', 'e.id', '=', 'c.employee_id')->select('c.*', 'e.number', 'e.full_name', 'e.department', 'e.user_id');
    }
}
