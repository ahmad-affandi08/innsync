<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Domain\Stays\GuestProfile;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Guest registrations. The implementation protects the identity details at rest; callers only see plain values. */
interface GuestRepository
{
    public function add(PropertyId $property, GuestProfile $guest, string $actorId, DateTimeImmutable $at): void;

    public function find(PropertyId $property, string $id): ?GuestProfile;

    /**
     * Earlier registrations with the same identity document (FR-FO-015), most recent first. Names only, no document details.
     *
     * @return list<array{guest_id: string, full_name: string, stays: int, last_stay: ?string}>
     */
    public function previousWithDocument(PropertyId $property, string $idType, string $idNumber, int $limit = 5): array;
}
