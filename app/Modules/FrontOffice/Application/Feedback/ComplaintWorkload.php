<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Feedback;

use App\Shared\Domain\Tenancy\PropertyId;

/** What Human Resource may ask the front office about guest complaints (FR-HR-021): how many a person owns, for the board of how a person is doing. It checks no privilege: the caller does. */
interface ComplaintWorkload
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, array{total: int, serious: int, resolved: int}> by person who owns the complaints, for those raised between two dates
     */
    public function ownedBy(PropertyId $property, array $userIds, string $fromUtc, string $toUtc): array;
}
