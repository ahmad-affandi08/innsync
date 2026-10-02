<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Companies;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Where a charge of a reservation billed to a company goes (FR-FO-035). Used by night audit and by charges raised for a guest, so a
 * company's room charges (and, if so agreed, everything else) land on the company's folio and not on the guest's own.
 */
interface CompanyRouting
{
    /**
     * The open folio the charge belongs on, or null when the reservation has no company, the company does not take this kind of
     * charge, or its folio is closed (the charge then goes to the guest's own folio as before).
     *
     * @param  'room'|'extras'  $category
     */
    public function routeTo(PropertyId $property, string $reservationId, string $category): ?string;

    /** Whether the folio is a company's: it may stay open with a balance after the guest has left. */
    public function isCompanyFolio(PropertyId $property, string $folioId): bool;
}
