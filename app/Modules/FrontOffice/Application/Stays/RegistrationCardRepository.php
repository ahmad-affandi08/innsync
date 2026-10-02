<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RegistrationCardRepository
{
    /** @return array{version: int, body: string}|null the newest terms */
    public function latestTerms(PropertyId $property): ?array;

    public function addTerms(PropertyId $property, string $id, string $body, string $reason, string $actorId, DateTimeImmutable $at): int;

    /** @return array{id: string, terms_body: ?string, terms_version: ?int, signature_file_id: string, recorded_by: string, signed_at: string}|null */
    public function find(PropertyId $property, string $stayId): ?array;

    /** @return bool false when the stay already has a signed card */
    public function add(PropertyId $property, string $id, string $stayId, ?string $termsBody, ?int $termsVersion, string $signatureFileId, string $actorId, DateTimeImmutable $at): bool;
}
