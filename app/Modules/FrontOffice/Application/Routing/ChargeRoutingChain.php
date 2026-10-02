<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Routing;

use App\Modules\FrontOffice\Application\Companies\CompanyRouting;
use App\Modules\FrontOffice\Application\Companies\CompanyService;
use App\Modules\FrontOffice\Application\Groups\GroupBookingService;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Where a charge of a reservation is posted: the company it is billed to first (FR-FO-035), then the master folio of its group
 * (FR-FO-006); otherwise nowhere special, and the caller uses the guest's own folio. A folio that takes the bill of a company or a
 * group may keep a balance after the guest leaves.
 */
final readonly class ChargeRoutingChain implements CompanyRouting
{
    public function __construct(private CompanyService $companies, private GroupBookingService $groups) {}

    public function routeTo(PropertyId $property, string $reservationId, string $category): ?string
    {
        return $this->companies->routeTo($property, $reservationId, $category) ?? $this->groups->routeTo($property, $reservationId, $category);
    }

    public function isCompanyFolio(PropertyId $property, string $folioId): bool
    {
        return $this->companies->isCompanyFolio($property, $folioId) || $this->groups->isCompanyFolio($property, $folioId);
    }
}
