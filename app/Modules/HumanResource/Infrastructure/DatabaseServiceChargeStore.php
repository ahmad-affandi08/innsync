<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\ServiceChargeStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseServiceChargeStore implements ServiceChargeStore
{
    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('hr_service_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : (array) $r;
    }

    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        if ($expectedLock === null) {
            try {
                DB::table('hr_service_settings')->insert([...$values, 'property_id' => $property->toString(), 'updated_by' => $by, 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('hr_service_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLock)->update([...$values, 'updated_by' => $by, 'lock_version' => $expectedLock + 1, 'updated_at' => $stamp]) === 1;
    }

    public function points(PropertyId $property): array
    {
        return DB::table('hr_service_points')->where('property_id', $property->toString())->orderBy('position')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function replacePoints(PropertyId $property, array $points): void
    {
        DB::table('hr_service_points')->where('property_id', $property->toString())->delete();

        foreach ($points as $p) {
            DB::table('hr_service_points')->insert([...$p, 'property_id' => $property->toString()]);
        }
    }

    public function addDistribution(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_service_distributions')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function distribution(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_service_distributions')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function distributions(PropertyId $property): array
    {
        return DB::table('hr_service_distributions')->where('property_id', $property->toString())->orderByDesc('period')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function updateDistribution(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_service_distributions')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function deleteDraft(PropertyId $property, string $id): void
    {
        DB::table('hr_service_lines')->where('property_id', $property->toString())->where('distribution_id', $id)->delete();
        DB::table('hr_service_distributions')->where('property_id', $property->toString())->where('id', $id)->where('status', 'draft')->delete();
    }

    public function replaceLines(PropertyId $property, string $distributionId, array $lines, DateTimeImmutable $at): void
    {
        DB::table('hr_service_lines')->where('property_id', $property->toString())->where('distribution_id', $distributionId)->delete();
        $stamp = $at->format('Y-m-d H:i:s.u');

        foreach ($lines as $l) {
            DB::table('hr_service_lines')->insert([...$l, 'property_id' => $property->toString(), 'distribution_id' => $distributionId, 'created_at' => $stamp]);
        }
    }

    public function lines(PropertyId $property, string $distributionId): array
    {
        return DB::table('hr_service_lines')->where('property_id', $property->toString())->where('distribution_id', $distributionId)->orderBy('full_name')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function approvedSharesFor(PropertyId $property, string $period): array
    {
        $out = [];

        foreach (DB::table('hr_service_lines as l')->join('hr_service_distributions as d', 'd.id', '=', 'l.distribution_id')->where('l.property_id', $property->toString())->where('d.period', $period)->where('d.status', 'approved')->get(['l.employee_id', 'l.share_minor']) as $r) {
            $out[(string) $r->employee_id] = (int) $r->share_minor;
        }

        return $out;
    }
}
