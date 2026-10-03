<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\PayrollDisbursementStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DatabasePayrollDisbursementStore implements PayrollDisbursementStore
{
    public function byRun(PropertyId $property, string $runId): ?array
    {
        $r = DB::table('finance_payroll_disbursements')->where('property_id', $property->toString())->where('run_id', $runId)->first();

        return $r === null ? null : (array) $r;
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('finance_payroll_disbursements')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function all(PropertyId $property): array
    {
        return DB::table('finance_payroll_disbursements')->where('property_id', $property->toString())->orderByDesc('period')->orderByDesc('created_at')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('finance_payroll_disbursements')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('finance_payroll_disbursements')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }
}
