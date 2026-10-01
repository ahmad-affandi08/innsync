<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ConsentRepository
{
    public function append(PropertyId $property, string $id, string $subjectType, string $subjectId, string $purpose, string $noticeVersion, bool $granted, ?string $evidenceRef, string $recordedBy, DateTimeImmutable $at): void;

    /** The latest record for the subject and purpose: granted or not, or null when none exists. */
    public function latest(PropertyId $property, string $subjectType, string $subjectId, string $purpose): ?bool;
}
