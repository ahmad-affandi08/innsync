<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Settings;

use App\Modules\Property\Application\Settings\PropertySettingsRepository;
use App\Modules\Property\Domain\Settings\PropertySettings;
use App\Modules\Property\Domain\Settings\TimeOfDay;
use App\Shared\Domain\Money\RoundingMode;
use App\Shared\Domain\Money\RoundingRule;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePropertySettingsRepository implements PropertySettingsRepository
{
    public function find(PropertyId $property): ?PropertySettings
    {
        $row = DB::table('property_settings')->where('property_id', $property->toString())->first();

        if ($row === null) {
            return null;
        }

        return new PropertySettings(
            $row->business_date === null ? null : BusinessDate::fromString(substr((string) $row->business_date, 0, 10)),
            TimeOfDay::fromString($row->check_in_time),
            TimeOfDay::fromString($row->check_out_time),
            TimeOfDay::fromString($row->night_audit_earliest_time),
            new RoundingRule((int) $row->rounding_increment_minor, RoundingMode::from($row->rounding_mode)),
            (int) $row->availability_horizon_days,
            (int) $row->lock_version,
        );
    }

    public function save(PropertyId $property, PropertySettings $settings, int $expectedLockVersion, string $actorId): bool
    {
        $values = [
            'business_date' => $settings->businessDate?->toString(),
            'check_in_time' => $settings->checkInTime->value,
            'check_out_time' => $settings->checkOutTime->value,
            'night_audit_earliest_time' => $settings->nightAuditEarliest->value,
            'rounding_increment_minor' => $settings->rounding->incrementMinor,
            'rounding_mode' => $settings->rounding->mode->value,
            'availability_horizon_days' => $settings->availabilityHorizonDays,
            'updated_by' => $actorId,
            'lock_version' => $expectedLockVersion + 1,
            'updated_at' => now(),
        ];

        if ($expectedLockVersion === 0 && ! DB::table('property_settings')->where('property_id', $property->toString())->exists()) {
            try {
                DB::table('property_settings')->insert(['property_id' => $property->toString(), 'created_at' => now(), ...$values]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('property_settings')
            ->where('property_id', $property->toString())
            ->where('lock_version', $expectedLockVersion)
            ->update($values) === 1;
    }
}
