<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What Human Resource hands to Finance when a payroll run is approved (FR-HR-031, -037), and takes back if the run is reopened before it was paid. The caller has authorised the action (it is the
 * approved run); Finance owns what happens next: it verifies the amounts and pays them.
 */
interface PayrollDisbursements
{
    /** Takes the run in to be verified and paid; a run that was withdrawn is taken in again with its new amounts. */
    public function receive(PropertyId $property, string $runId, string $number, string $period, int $employees, int $netMinor, int $taxMinor, int $employeeSocialMinor, int $employerSocialMinor, string $byUserId): void;

    /** Gives the run back. Refused when it was paid already. */
    public function withdraw(PropertyId $property, string $runId, string $byUserId): void;

    /** awaiting, verified, paid or withdrawn; null when Finance never got the run. */
    public function statusOf(PropertyId $property, string $runId): ?string;
}
