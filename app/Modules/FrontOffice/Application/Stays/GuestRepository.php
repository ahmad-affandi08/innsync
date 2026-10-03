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

    /** Several registrations in one query (a list of stays). @param list<string> $ids @return array<string, GuestProfile> by guest id */
    public function findMany(PropertyId $property, array $ids): array;

    /** Replaces the registration details of a guest (a correction, FR-FO-039). The identity details stay protected at rest. */
    public function replace(PropertyId $property, GuestProfile $guest, DateTimeImmutable $at): void;

    /** Keeps what a correction changed: the field and its value before and after, sealed. @param array{id: string, guest_id: string, stay_id: string, field: string, old: ?string, new: ?string, reason: string, approval_id: ?string} $correction */
    public function addCorrection(PropertyId $property, array $correction, string $actorId, DateTimeImmutable $at): void;

    /** @return list<array{field: string, old: ?string, new: ?string, reason: string, approval_id: ?string, created_by: string, created_at: string}> oldest first */
    public function corrections(PropertyId $property, string $stayId): array;

    /**
     * Earlier registrations with the same identity document (FR-FO-015), most recent first. Names only, no document details.
     *
     * @return list<array{guest_id: string, full_name: string, stays: int, last_stay: ?string}>
     */
    public function previousWithDocument(PropertyId $property, string $idType, string $idNumber, int $limit = 5): array;
}
