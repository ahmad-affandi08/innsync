<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\AppraisalStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseAppraisalStore implements AppraisalStore
{
    public function addForm(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_appraisal_forms')->insert([...$row, 'criteria' => json_encode($row['criteria'], JSON_THROW_ON_ERROR), 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function form(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_appraisal_forms')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function forms(PropertyId $property, bool $onlyActive): array
    {
        return DB::table('hr_appraisal_forms')->where('property_id', $property->toString())->when($onlyActive, static fn ($q) => $q->where('is_active', true))->orderBy('name')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function updateForm(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_appraisal_forms')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_appraisals')->insert([...$row, 'criteria' => json_encode($row['criteria'], JSON_THROW_ON_ERROR), 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = $this->joined()->where('a.property_id', $property->toString())->where('a.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function list(PropertyId $property, ?string $employeeId, int $limit): array
    {
        return $this->joined()->where('a.property_id', $property->toString())->when($employeeId !== null, static fn ($q) => $q->where('a.employee_id', $employeeId))->orderByDesc('a.period_end')->orderByDesc('a.created_at')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        foreach (['scores', 'metrics'] as $json) {
            if (array_key_exists($json, $fields) && $fields[$json] !== null) {
                $fields[$json] = json_encode($fields[$json], JSON_THROW_ON_ERROR);
            }
        }

        return DB::table('hr_appraisals')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function nextNumber(PropertyId $property, string $year): string
    {
        $count = DB::table('hr_appraisals')->where('property_id', $property->toString())->where('number', 'like', "APR-{$year}-%")->count();

        return sprintf('APR-%s-%04d', $year, $count + 1);
    }

    private function joined(): Builder
    {
        return DB::table('hr_appraisals as a')->join('hr_employees as e', 'e.id', '=', 'a.employee_id')->leftJoin('hr_employees as s', 's.id', '=', 'e.supervisor_id')
            ->select('a.*', 'e.number as employee_number', 'e.full_name as employee_name', 'e.department', 'e.position', 'e.user_id as employee_user', 'e.supervisor_id', 's.user_id as supervisor_user', 's.full_name as supervisor_name');
    }
}
