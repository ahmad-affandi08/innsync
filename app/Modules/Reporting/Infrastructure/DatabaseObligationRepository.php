<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ObligationRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseObligationRepository implements ObligationRepository
{
    public function settings(PropertyId $property): ?array
    {
        $row = DB::table('obligation_settings')->where('property_id', $property->toString())->first();

        return $row === null ? null : ['tax_report_day' => (int) $row->tax_report_day, 'service_employee_share_bp' => (int) $row->service_employee_share_bp, 'lock_version' => (int) $row->lock_version];
    }

    public function saveSettings(PropertyId $property, int $day, int $shareBp, ?int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool
    {
        if ($expectedLockVersion === null) {
            try {
                DB::table('obligation_settings')->insert(['property_id' => $property->toString(), 'tax_report_day' => $day, 'service_employee_share_bp' => $shareBp, 'lock_version' => 0, 'updated_by' => $actorId, 'updated_at' => $at]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        }

        return DB::table('obligation_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLockVersion)
            ->update(['tax_report_day' => $day, 'service_employee_share_bp' => $shareBp, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $actorId, 'updated_at' => $at]) === 1;
    }

    public function filings(PropertyId $property): array
    {
        $result = [];

        foreach (DB::table('tax_filings')->where('property_id', $property->toString())->get() as $r) {
            $result[$r->period_month] = ['period_month' => $r->period_month, 'reported_on' => substr((string) $r->reported_on, 0, 10), 'reference' => $r->reference, 'tax_minor' => (int) $r->tax_minor];
        }

        return $result;
    }

    public function addFiling(PropertyId $property, string $id, string $month, string $reportedOn, string $reference, int $taxMinor, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('tax_filings')->insert(['id' => $id, 'property_id' => $property->toString(), 'period_month' => $month, 'reported_on' => $reportedOn, 'reference' => $reference, 'tax_minor' => $taxMinor, 'reported_by' => $actorId, 'created_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
