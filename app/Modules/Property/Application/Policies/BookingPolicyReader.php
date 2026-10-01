<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Policies;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/** What Front Office asks when a reservation is made: the policy that applies, as plain data. Read-only; the caller authorizes its use. */
interface BookingPolicyReader
{
    /**
     * The most specific policy in force on `$on` for this rate plan and booking source, in the form a reservation keeps as its
     * snapshot, or null when none has been configured (then no deposit and no penalty apply).
     *
     * @return array<string, mixed>|null
     */
    public function policyFor(PropertyId $property, string $ratePlanId, string $source, BusinessDate $on): ?array;
}
