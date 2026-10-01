<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Privacy;

use App\Shared\Application\Privacy\ConsentRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseConsentRepository implements ConsentRepository
{
    public function append(PropertyId $property, string $id, string $subjectType, string $subjectId, string $purpose, string $noticeVersion, bool $granted, ?string $evidenceRef, string $recordedBy, DateTimeImmutable $at): void
    {
        DB::table('consent_records')->insert([
            'id' => $id,
            'property_id' => $property->toString(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'purpose' => $purpose,
            'notice_version' => $noticeVersion,
            'granted' => $granted,
            'evidence_ref' => $evidenceRef,
            'recorded_by' => $recordedBy,
            'recorded_at' => $at,
        ]);
    }

    public function latest(PropertyId $property, string $subjectType, string $subjectId, string $purpose): ?bool
    {
        $granted = DB::table('consent_records')
            ->where('property_id', $property->toString())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('purpose', $purpose)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->value('granted');

        return $granted === null ? null : (bool) $granted;
    }
}
