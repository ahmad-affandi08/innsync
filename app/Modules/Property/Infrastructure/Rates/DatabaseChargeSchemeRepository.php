<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Rates;

use App\Modules\Property\Application\Rates\ChargeSchemeRepository;
use App\Modules\Property\Domain\Rates\ChargeSchemeConfig;
use App\Shared\Domain\Money\Percentage;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseChargeSchemeRepository implements ChargeSchemeRepository
{
    public function add(PropertyId $property, ChargeSchemeConfig $config, string $reason, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('charge_schemes')->insert([
                'id' => $config->id, 'property_id' => $property->toString(), 'scope' => $config->scope, 'effective_from' => $config->effectiveFrom->toString(),
                'service_charge_bp' => $config->serviceCharge->basisPoints, 'tax_bp' => $config->tax->basisPoints, 'tax_on_service_charge' => $config->taxOnServiceCharge,
                'change_reason' => $reason, 'created_by' => $actorId, 'created_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function forScope(PropertyId $property, string $scope): array
    {
        return DB::table('charge_schemes')->where('property_id', $property->toString())->where('scope', $scope)->orderByDesc('effective_from')->get()
            ->map(static fn (stdClass $r): ChargeSchemeConfig => new ChargeSchemeConfig(
                $r->id, $r->scope, BusinessDate::fromString(substr((string) $r->effective_from, 0, 10)),
                Percentage::ofBasisPoints((int) $r->service_charge_bp), Percentage::ofBasisPoints((int) $r->tax_bp), (bool) $r->tax_on_service_charge,
            ))->all();
    }
}
