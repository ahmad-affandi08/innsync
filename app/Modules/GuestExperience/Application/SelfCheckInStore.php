<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the privacy notices, the self check-in links and what guests sent through them. Rows are plain arrays; every query except the lookup of a link by its hash is scoped to the property. */
interface SelfCheckInStore
{
    /** @return array<string, mixed>|null the newest version of the notice */
    public function latestNotice(PropertyId $property): ?array;

    /** @param array<string, mixed> $row @return bool false when the version exists already */
    public function addNotice(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addLink(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the link a token hash opens, whichever property it is of */
    public function linkByHash(string $hash): ?array;

    /** @return array<string, mixed>|null */
    public function link(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> the links not revoked and not expired at a moment, newest first; of one reservation, or the lobby ones when `$reservationId` is null */
    public function liveLinks(PropertyId $property, ?string $reservationId, DateTimeImmutable $at): array;

    /** @param array<string, mixed> $fields @return bool false when the link changed meanwhile */
    public function updateLink(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    public function addAttempt(PropertyId $property, string $subjectHash, DateTimeImmutable $at): void;

    public function attemptsSince(PropertyId $property, string $subjectHash, DateTimeImmutable $since): int;

    /** @param array<string, mixed> $row @return bool false when the link has a submission already */
    public function addCheckin(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function checkin(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null the newest submission for a reservation that was not rejected, or failing that the newest rejected one */
    public function checkinOfReservation(PropertyId $property, string $reservationId): ?array;

    /** @return list<array<string, mixed>> */
    public function checkinsByStatus(PropertyId $property, string $status, int $limit): array;

    /** @param array<string, mixed> $fields @return bool false when the submission changed meanwhile */
    public function decide(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;
}
