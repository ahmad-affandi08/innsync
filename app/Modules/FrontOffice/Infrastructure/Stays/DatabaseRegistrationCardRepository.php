<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Stays;

use App\Modules\FrontOffice\Application\Stays\RegistrationCardRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseRegistrationCardRepository implements RegistrationCardRepository
{
    public function latestTerms(PropertyId $property): ?array
    {
        $row = DB::table('registration_terms')->where('property_id', $property->toString())->orderByDesc('version')->first();

        return $row === null ? null : ['version' => (int) $row->version, 'body' => (string) $row->body];
    }

    public function addTerms(PropertyId $property, string $id, string $body, string $reason, string $actorId, DateTimeImmutable $at): int
    {
        $version = (int) DB::table('registration_terms')->where('property_id', $property->toString())->max('version') + 1;
        DB::table('registration_terms')->insert(['id' => $id, 'property_id' => $property->toString(), 'version' => $version, 'body' => $body, 'reason' => $reason, 'created_by' => $actorId, 'created_at' => $at]);

        return $version;
    }

    public function find(PropertyId $property, string $stayId): ?array
    {
        $r = DB::table('registration_cards')->where('property_id', $property->toString())->where('stay_id', $stayId)->first();

        return $r === null ? null : [
            'id' => $r->id, 'terms_body' => $r->terms_body, 'terms_version' => $r->terms_version === null ? null : (int) $r->terms_version, 'signature_file_id' => $r->signature_file_id,
            'recorded_by' => $r->recorded_by, 'signed_at' => (new DateTimeImmutable((string) $r->signed_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function add(PropertyId $property, string $id, string $stayId, ?string $termsBody, ?int $termsVersion, string $signatureFileId, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('registration_cards')->insert(['id' => $id, 'property_id' => $property->toString(), 'stay_id' => $stayId, 'terms_body' => $termsBody, 'terms_version' => $termsVersion, 'signature_file_id' => $signatureFileId, 'recorded_by' => $actorId, 'signed_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
