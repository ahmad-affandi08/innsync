<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the requests, the complaints and the surveys of guests. Rows are plain arrays; every query is scoped to the property. */
interface GuestHelpStore
{
    /** @param array<string, mixed> $row */
    public function addRequest(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the request a session sent under a key, so a retry returns it */
    public function requestByKey(PropertyId $property, string $sessionId, string $clientKey): ?array;

    /** @return list<array<string, mixed>> what a guest of one stay asked for, newest first */
    public function requestsOfStay(PropertyId $property, string $stayId, int $limit): array;

    /** @return int what a session sent since a moment */
    public function requestsSince(PropertyId $property, string $sessionId, DateTimeImmutable $since): int;

    /** @param array<string, mixed> $row @return bool false when the stay has a survey already */
    public function addSurvey(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function surveyOfStay(PropertyId $property, string $stayId): ?array;

    /** @return list<array<string, mixed>> the surveys since a moment, newest first */
    public function surveysSince(PropertyId $property, DateTimeImmutable $since, int $limit): array;
}
