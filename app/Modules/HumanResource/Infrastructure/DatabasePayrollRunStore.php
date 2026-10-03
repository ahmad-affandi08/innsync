<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\PayrollRunStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabasePayrollRunStore implements PayrollRunStore
{
    public function addRun(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_payroll_runs')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function run(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_payroll_runs')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function runs(PropertyId $property): array
    {
        return DB::table('hr_payroll_runs')->where('property_id', $property->toString())->orderByDesc('period')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function updateRun(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_payroll_runs')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function deleteDraft(PropertyId $property, string $id): void
    {
        DB::table('hr_payroll_lines')->where('property_id', $property->toString())->where('run_id', $id)->delete();
        DB::table('hr_payroll_runs')->where('property_id', $property->toString())->where('id', $id)->where('status', 'draft')->delete();
    }

    public function replaceLines(PropertyId $property, string $runId, array $lines, DateTimeImmutable $at): void
    {
        DB::table('hr_payroll_lines')->where('property_id', $property->toString())->where('run_id', $runId)->delete();
        $stamp = $at->format('Y-m-d H:i:s.u');

        foreach ($lines as $l) {
            DB::table('hr_payroll_lines')->insert([...$l, 'property_id' => $property->toString(), 'run_id' => $runId, 'items' => json_encode($l['items'], JSON_THROW_ON_ERROR), 'warnings' => json_encode($l['warnings'], JSON_THROW_ON_ERROR), 'created_at' => $stamp]);
        }
    }

    public function lines(PropertyId $property, string $runId): array
    {
        return DB::table('hr_payroll_lines')->where('property_id', $property->toString())->where('run_id', $runId)->orderBy('full_name')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function addAdjustment(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_payroll_adjustments')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function adjustment(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_payroll_adjustments as a')->join('hr_employees as e', 'e.id', '=', 'a.employee_id')->leftJoin('hr_payroll_runs as s', 's.id', '=', 'a.source_run_id')
            ->where('a.property_id', $property->toString())->where('a.id', $id)->select('a.*', 'e.number', 'e.full_name', 's.period as source_period')->first();

        return $r === null ? null : (array) $r;
    }

    public function updateAdjustment(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_payroll_adjustments')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function adjustments(PropertyId $property, ?string $appliedRunId): array
    {
        return DB::table('hr_payroll_adjustments as a')->join('hr_employees as e', 'e.id', '=', 'a.employee_id')->leftJoin('hr_payroll_runs as s', 's.id', '=', 'a.source_run_id')
            ->where('a.property_id', $property->toString())->where(static function ($q) use ($appliedRunId): void {
                $q->where('a.status', 'open');

                if ($appliedRunId !== null) {
                    $q->orWhere('a.applied_run_id', $appliedRunId);
                }
            })->orderByDesc('a.created_at')->select('a.*', 'e.number', 'e.full_name', 's.period as source_period')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function releaseAdjustments(PropertyId $property, string $runId, DateTimeImmutable $at): void
    {
        DB::table('hr_payroll_adjustments')->where('property_id', $property->toString())->where('applied_run_id', $runId)->update(['status' => 'open', 'applied_run_id' => null, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function addRevision(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('hr_payroll_revisions')->insert([...$row, 'property_id' => $property->toString(), 'snapshot' => json_encode($row['snapshot'], JSON_THROW_ON_ERROR), 'created_at' => $at->format('Y-m-d H:i:s.u')]);
    }
}
