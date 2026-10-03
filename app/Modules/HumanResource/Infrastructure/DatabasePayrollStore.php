<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\PayrollStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabasePayrollStore implements PayrollStore
{
    public function components(PropertyId $property, bool $onlyActive): array
    {
        return DB::table('hr_pay_components')->where('property_id', $property->toString())->when($onlyActive, static fn ($q) => $q->where('is_active', true))->orderBy('code')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function addComponent(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_pay_components')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function component(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_pay_components')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function updateComponent(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_pay_components')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function addItem(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('hr_pay_items')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at->format('Y-m-d H:i:s.u')]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function items(PropertyId $property, ?string $employeeId): array
    {
        return DB::table('hr_pay_items')->where('property_id', $property->toString())->when($employeeId !== null, static fn ($q) => $q->where('employee_id', $employeeId))->orderBy('effective_from')->orderBy('created_at')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function profile(PropertyId $property, string $employeeId): ?array
    {
        $r = DB::table('hr_pay_profiles')->where('property_id', $property->toString())->where('employee_id', $employeeId)->first();

        return $r === null ? null : (array) $r;
    }

    public function profiles(PropertyId $property): array
    {
        return DB::table('hr_pay_profiles')->where('property_id', $property->toString())->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function saveProfile(PropertyId $property, string $employeeId, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $row = ['ptkp_status' => $values['ptkp_status'], 'has_npwp' => $values['has_npwp'], 'in_health' => $values['in_health'], 'in_employment' => $values['in_employment']];
        $stamp = $at->format('Y-m-d H:i:s.u');

        if ($expectedLock === null) {
            try {
                DB::table('hr_pay_profiles')->insert([...$row, 'employee_id' => $employeeId, 'property_id' => $property->toString(), 'updated_by' => $by, 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('hr_pay_profiles')->where('property_id', $property->toString())->where('employee_id', $employeeId)->where('lock_version', $expectedLock)->update([...$row, 'updated_by' => $by, 'lock_version' => $expectedLock + 1, 'updated_at' => $stamp]) === 1;
    }

    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('hr_payroll_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : (array) $r;
    }

    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $row = [...$values, 'ptkp' => json_encode($values['ptkp'], JSON_THROW_ON_ERROR), 'brackets' => json_encode($values['brackets'], JSON_THROW_ON_ERROR)];
        $stamp = $at->format('Y-m-d H:i:s.u');

        if ($expectedLock === null) {
            try {
                DB::table('hr_payroll_settings')->insert([...$row, 'property_id' => $property->toString(), 'updated_by' => $by, 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('hr_payroll_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLock)->update([...$row, 'updated_by' => $by, 'lock_version' => $expectedLock + 1, 'updated_at' => $stamp]) === 1;
    }
}
