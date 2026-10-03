<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\TaxStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseTaxStore implements TaxStore
{
    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('fin_tax_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : (array) $r;
    }

    public function saveSettings(PropertyId $property, int $reportDay, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        if ($expectedLock === null) {
            try {
                DB::table('fin_tax_settings')->insert(['property_id' => $property->toString(), 'report_day' => $reportDay, 'updated_by' => $by, 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('fin_tax_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLock)->update(['report_day' => $reportDay, 'updated_by' => $by, 'lock_version' => $expectedLock + 1, 'updated_at' => $stamp]) === 1;
    }

    public function filings(PropertyId $property): array
    {
        $out = [];

        foreach (DB::table('fin_tax_filings')->where('property_id', $property->toString())->get() as $r) {
            $out[(string) $r->period] = (array) $r;
        }

        return $out;
    }

    public function addFiling(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('fin_tax_filings')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function updateFiling(PropertyId $property, string $period, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fin_tax_filings')->where('property_id', $property->toString())->where('period', $period)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }
}
